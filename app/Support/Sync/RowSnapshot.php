<?php

namespace App\Support\Sync;

use App\Support\Ids;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Row snapshots for operational sync events (orders, payments, cash, stock, ...).
 *
 * A snapshot is an ordered list of `{table, rows}` blocks, parents first. Binary columns (ids) travel as `{"$b": "<hex>"}`;
 * generated columns are skipped. Only tables in ALLOWED can be written by a received snapshot, and column names are checked
 * against the real schema, so a peer can never name an arbitrary table or column.
 *
 * Applying is an idempotent upsert by primary key: a row we already hold at a strictly newer `row_version` is left
 * alone (never overwrite newer data with older), tables without a version are simply rewritten. Foreign-key checks are off
 * while a snapshot lands, because a row may name a device or a user that arrives with its own event a moment later.
 */
final class RowSnapshot
{
    /** Tables a peer may write through a snapshot. */
    public const ALLOWED = [
        'tab', 'order', 'order_line', 'line_adjustment', 'line_void',
        'payment', 'payment_allocation', 'payment_collection', 'payment_collection_decision', 'receipt', 'receipt_order', 'refund', 'reversal',
        'payment_terminal',
        'cash_session', 'cash_movement', 'cash_in_hand_entry', 'cash_handover',
        'purchase_receipt', 'purchase_receipt_line', 'stock_movement', 'stock_balance', 'stock_transfer', 'stock_transfer_line',
        'stock_adjustment', 'stock_adjustment_line', 'stock_count', 'stock_count_line',
        'device', 'membership', 'membership_usage', 'membership_status_history', 'member_card',
        'customer', 'customer_account', 'attendance_correction', 'attendance_day',
    ];

    /** @var array<string, array{cols: array<string, array{type: string, gen: bool}>, pk: list<string>}> */
    private static array $schema = [];

    /** @return array{cols: array<string, array{type: string, gen: bool}>, pk: list<string>} */
    private static function schema(string $table, ?string $connection = null): array
    {
        $db = DB::connection($connection);
        $key = $db->getDatabaseName().'.'.$table;
        if (isset(self::$schema[$key])) {
            return self::$schema[$key];
        }
        $rows = $db->select('SELECT column_name AS c, data_type AS t, extra AS x, column_key AS k FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position', [$table]);
        if ($rows === []) {
            throw new InvalidArgumentException("Unknown table '$table'.");
        }
        $cols = [];
        $pk = [];
        foreach ($rows as $r) {
            $x = strtoupper((string) $r->x);
            $cols[$r->c] = ['type' => strtolower((string) $r->t), 'gen' => str_contains($x, 'GENERATED') && ! str_contains($x, 'DEFAULT_GENERATED')];
            if ($r->k === 'PRI') {
                $pk[] = $r->c;
            }
        }

        return self::$schema[$key] = ['cols' => $cols, 'pk' => $pk];
    }

    private static function isBinary(string $type): bool
    {
        return in_array($type, ['binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob'], true);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: list<string|int>|null}>  $plan  [table, where-column, values] in apply order; null values = skip
     * @return list<array{table: string, rows: list<array<string, mixed>>}>
     */
    public static function capture(array $plan): array
    {
        $out = [];
        foreach ($plan as [$table, $col, $values]) {
            if (! in_array($table, self::ALLOWED, true)) {
                throw new InvalidArgumentException("Table '$table' is not syncable.");
            }
            $values = array_values(array_filter((array) $values, fn ($v) => $v !== null && $v !== ''));
            if ($values === []) {
                continue;
            }
            $schema = self::schema($table);
            $binCol = self::isBinary($schema['cols'][$col]['type'] ?? '');
            $params = array_map(fn ($v) => $binCol ? Ids::toBinary((string) $v) : $v, $values);
            $rows = DB::select("SELECT * FROM `$table` WHERE `$col` IN (".implode(',', array_fill(0, count($params), '?')).')', $params);
            $enc = [];
            foreach ($rows as $row) {
                $r = [];
                foreach ((array) $row as $name => $v) {
                    if (($schema['cols'][$name]['gen'] ?? false) || $v === null) {
                        if ($v === null) {
                            $r[$name] = null;
                        }

                        continue;
                    }
                    $r[$name] = self::isBinary($schema['cols'][$name]['type'] ?? '') ? ['$b' => bin2hex((string) $v)] : $v;
                }
                $enc[] = $r;
            }
            if ($enc !== []) {
                $out[] = ['table' => $table, 'rows' => $enc];
            }
        }

        return $out;
    }

    /**
     * @param  list<array{table: string, rows: list<array<string, mixed>>}>  $snapshot
     * @return int number of rows written
     */
    public static function apply(array $snapshot, ?string $connection = null): int
    {
        $written = 0;
        $db = DB::connection($connection);
        $db->statement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($snapshot as $block) {
                $table = (string) ($block['table'] ?? '');
                if (! in_array($table, self::ALLOWED, true)) {
                    throw new InvalidArgumentException("Table '$table' is not syncable.");
                }
                $schema = self::schema($table, $connection);
                foreach ($block['rows'] ?? [] as $row) {
                    $written += self::upsert($db, $table, $schema, (array) $row);
                }
            }
        } finally {
            $db->statement('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $written;
    }

    /** @param array{cols: array<string, array{type: string, gen: bool}>, pk: list<string>} $schema */
    private static function upsert(Connection $db, string $table, array $schema, array $row): int
    {
        $vals = [];
        foreach ($row as $name => $v) {
            if (! isset($schema['cols'][$name])) {
                throw new InvalidArgumentException("Unknown column '$name' for '$table'.");
            }
            if ($schema['cols'][$name]['gen']) {
                continue;
            }
            $vals[$name] = is_array($v) && isset($v['$b']) ? hex2bin((string) $v['$b']) : $v;
        }
        foreach ($schema['pk'] as $k) {
            if (! array_key_exists($k, $vals)) {
                throw new InvalidArgumentException("Row for '$table' is missing key '$k'.");
            }
        }
        if (isset($vals['row_version'], $schema['cols']['row_version'])) {
            $q = $db->table($table);
            foreach ($schema['pk'] as $k) {
                $q->where($k, $vals[$k]);
            }
            $local = $q->value('row_version');
            if ($local !== null && (int) $local > (int) $vals['row_version']) {
                return 0; // we already hold a newer version (children such as order lines change without a new version, so equal is rewritten)
            }
        }
        $cols = array_keys($vals);
        $sql = "INSERT INTO `$table` (".implode(',', array_map(fn ($c) => "`$c`", $cols)).') VALUES ('.implode(',', array_fill(0, count($cols), '?')).')';
        $upd = array_filter($cols, fn ($c) => ! in_array($c, $schema['pk'], true));
        $sql .= $upd === [] ? ' ON DUPLICATE KEY UPDATE `'.$schema['pk'][0].'` = `'.$schema['pk'][0].'`'
            : ' ON DUPLICATE KEY UPDATE '.implode(',', array_map(fn ($c) => "`$c` = VALUES(`$c`)", $upd));
        $db->insert($sql, array_values($vals));

        return 1;
    }
}
