<?php

namespace App\Support\Audit;

use App\Support\Ids;
use App\Support\RequestContext;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Append-only, hash-chained audit log (architecture/04 §3.5, 17 §5).
 *
 *   DB::transaction(function () use (...) {
 *       $order->update([...]);                       // the change
 *       Audit::record('order.void', 'Order', $order->id, old: [...], new: [...]);  // same txn
 *   });
 *
 * row_hash = SHA256(prev_hash || canonical_json(row)). The tail row is read with
 * SELECT ... FOR UPDATE inside the caller's transaction so concurrent writers append one at a
 * time (a rolled-back business change therefore never leaves an audit row behind, and vice versa).
 * If called outside a transaction it opens its own. Actor / org / site / device default from the
 * request context; pass them explicitly from queue jobs / artisan.
 *
 * Money / decimals in old/new values MUST be strings (json floats are lossy). `verifyChain()` lets
 * IT/tests detect tampering (edit, delete-in-the-middle, reorder).
 */
final class Audit
{
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     * @return string audit row id (UUID)
     */
    public static function record(
        string $action,
        string $entityType,
        string $entityId,
        ?array $old = null,
        ?array $new = null,
        ?string $organizationId = null,
        ?string $siteId = null,
        ?string $actorStaffId = null,
        ?string $facilityUnitId = null,
        ?string $operatingPointId = null,
        ?string $deviceId = null,
        ?string $approvalId = null,
    ): string {
        $organizationId ??= Tenant::organizationId();
        $siteId ??= Tenant::siteId();
        if ($organizationId === null || $siteId === null) {
            throw new LogicException('Audit::record needs an organization and site (none in context and none passed).');
        }
        $actorStaffId ??= RequestContext::staffId();
        $deviceId ??= RequestContext::deviceId();

        $write = function () use (
            $action, $entityType, $entityId, $old, $new, $organizationId, $siteId, $actorStaffId,
            $facilityUnitId, $operatingPointId, $deviceId, $approvalId
        ): string {
            $prevHash = self::lockHead();

            $id = Ids::uuid7();
            $occurredAt = CarbonImmutable::now('UTC');
            $oldJson = $old === null ? null : self::canonicalize($old);
            $newJson = $new === null ? null : self::canonicalize($new);

            $canonical = self::canonicalRow([
                'id' => $id,
                'occurredAt' => self::formatTime($occurredAt->format('Y-m-d H:i:s.u')),
                'organizationId' => Ids::normalize($organizationId),
                'siteId' => Ids::normalize($siteId),
                'actorStaffId' => self::n($actorStaffId),
                'facilityUnitId' => self::n($facilityUnitId),
                'operatingPointId' => self::n($operatingPointId),
                'deviceId' => self::n($deviceId),
                'action' => $action,
                'entityType' => $entityType,
                'entityId' => Ids::normalize($entityId),
                'oldValue' => $oldJson,
                'newValue' => $newJson,
                'approvalId' => self::n($approvalId),
                'prevHash' => $prevHash,
            ]);

            $rowHash = hash('sha256', $prevHash.$canonical);
            $seq = DB::table('audit_log')->insertGetId([
                'id' => Ids::toBinary($id),
                'occurred_at' => $occurredAt->format('Y-m-d H:i:s.u'),
                'organization_id' => Ids::toBinary($organizationId),
                'site_id' => Ids::toBinary($siteId),
                'actor_staff_id' => self::b($actorStaffId),
                'facility_unit_id' => self::b($facilityUnitId),
                'operating_point_id' => self::b($operatingPointId),
                'device_id' => self::b($deviceId),
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => Ids::toBinary($entityId),
                'old_value' => $oldJson,
                'new_value' => $newJson,
                'approval_id' => self::b($approvalId),
                'prev_hash' => $prevHash,
                'row_hash' => $rowHash,
            ], 'seq');
            DB::table('audit_chain_head')->where('id', 1)->update(['tail_seq' => $seq, 'tail_hash' => $rowHash]);

            return $id;
        };

        return DB::transactionLevel() > 0 ? $write() : DB::transaction($write, 3);
    }

    /**
     * Take the chain mutex (row lock on the single audit_chain_head row) and return the current tail hash.
     * Holding it until the caller's transaction ends serialises all audit writers without gap-lock deadlocks.
     */
    private static function lockHead(): string
    {
        $head = DB::selectOne('SELECT tail_hash FROM audit_chain_head WHERE id = 1 FOR UPDATE');
        if ($head === null) { // head row missing (manual surgery / restored DB): rebuild from the log
            DB::statement('INSERT IGNORE INTO audit_chain_head (id, tail_seq, tail_hash) VALUES (1, 0, ?)', [self::GENESIS_HASH]);
            $head = DB::selectOne('SELECT tail_hash FROM audit_chain_head WHERE id = 1 FOR UPDATE');
            $last = DB::selectOne('SELECT seq, row_hash FROM audit_log ORDER BY seq DESC LIMIT 1');
            if ($last !== null) {
                DB::table('audit_chain_head')->where('id', 1)->update(['tail_seq' => $last->seq, 'tail_hash' => $last->row_hash]);

                return $last->row_hash;
            }
        }

        return $head->tail_hash;
    }

    /** Non-chained security signal (login failures, token reuse...). Best-effort, own row. */
    public static function securityEvent(string $eventType, string $severity = 'INFO', ?string $actorStaffId = null, ?string $ip = null, ?array $details = null, ?string $deviceId = null): void
    {
        DB::table('security_event')->insert([
            'id' => Ids::toBinary(Ids::uuid7()),
            'occurred_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            'event_type' => $eventType,
            'severity' => $severity,
            'actor_staff_id' => self::b($actorStaffId ?? RequestContext::staffId()),
            'device_id' => self::b($deviceId ?? RequestContext::deviceId()),
            'ip_address' => $ip ?? (request()->ip()),
            'details' => $details === null ? null : self::canonicalize($details),
        ]);
    }

    /**
     * Walk the chain in `seq` order recomputing every hash and prev-link.
     * Detects edited rows, deleted middle rows and reordering. (Truncating the TAIL is only
     * detected via audit_chain_head; an attacker who edits both is only caught by an externally anchored hash.)
     */
    public static function verifyChain(int $chunk = 500): ChainVerification
    {
        $prev = self::GENESIS_HASH;
        $checked = 0;
        $lastSeq = 0;

        while (true) {
            $rows = DB::table('audit_log')->where('seq', '>', $lastSeq)->orderBy('seq')->limit($chunk)->get();
            if ($rows->isEmpty()) {
                $head = DB::table('audit_chain_head')->where('id', 1)->first();
                if ($head !== null && ($head->tail_hash !== $prev || (int) $head->tail_seq !== $lastSeq)) {
                    return new ChainVerification(false, $checked, $lastSeq ?: null, 'chain tail does not match audit_chain_head (rows appended outside Audit::record or the tail was truncated).', $prev);
                }

                return new ChainVerification(true, $checked, null, null, $prev);
            }
            foreach ($rows as $r) {
                if ($r->prev_hash !== $prev) {
                    return new ChainVerification(false, $checked, (int) $r->seq, 'prev_hash does not match the previous row (row deleted, inserted or reordered).', $prev);
                }
                $canonical = self::canonicalRow([
                    'id' => Ids::fromBinary($r->id),
                    'occurredAt' => self::formatTime($r->occurred_at),
                    'organizationId' => Ids::fromBinary($r->organization_id),
                    'siteId' => Ids::fromBinary($r->site_id),
                    'actorStaffId' => self::u($r->actor_staff_id),
                    'facilityUnitId' => self::u($r->facility_unit_id),
                    'operatingPointId' => self::u($r->operating_point_id),
                    'deviceId' => self::u($r->device_id),
                    'action' => $r->action,
                    'entityType' => $r->entity_type,
                    'entityId' => Ids::fromBinary($r->entity_id),
                    'oldValue' => $r->old_value === null ? null : self::canonicalize(json_decode($r->old_value)),
                    'newValue' => $r->new_value === null ? null : self::canonicalize(json_decode($r->new_value)),
                    'approvalId' => self::u($r->approval_id),
                    'prevHash' => $r->prev_hash,
                ]);
                if (! hash_equals(hash('sha256', $r->prev_hash.$canonical), $r->row_hash)) {
                    return new ChainVerification(false, $checked, (int) $r->seq, 'row_hash does not match the row contents (row was modified).', $prev);
                }
                $prev = $r->row_hash;
                $lastSeq = (int) $r->seq;
                $checked++;
            }
        }
    }

    /** Deterministic JSON: keys sorted recursively, no escaping of slashes/unicode. */
    public static function canonicalize(mixed $value): string
    {
        return json_encode(self::sortKeys(json_decode(json_encode($value, JSON_THROW_ON_ERROR))), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function sortKeys(mixed $v): mixed
    {
        if ($v instanceof \stdClass) {
            $arr = (array) $v;
            ksort($arr, SORT_STRING);

            return (object) array_map(self::sortKeys(...), $arr);
        }
        if (is_array($v)) {
            return array_map(self::sortKeys(...), $v);
        }

        return $v;
    }

    /** @param array<string, ?string> $fields fixed order == hash input order */
    private static function canonicalRow(array $fields): string
    {
        return json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function formatTime(string $mysqlDateTime): string
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i:s.u', str_contains($mysqlDateTime, '.') ? $mysqlDateTime : $mysqlDateTime.'.000000', 'UTC')
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function n(?string $uuid): ?string
    {
        return $uuid === null ? null : Ids::normalize($uuid);
    }

    private static function b(?string $uuid): ?string
    {
        return $uuid === null ? null : Ids::toBinary($uuid);
    }

    private static function u(?string $binary): ?string
    {
        return $binary === null ? null : Ids::fromBinary($binary);
    }
}
