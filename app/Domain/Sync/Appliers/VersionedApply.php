<?php

namespace App\Domain\Sync\Appliers;

use App\Domain\Sync\Support\ApplyResult;
use App\Domain\Sync\Support\InboundEvent;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Generic version-checked apply for entities editable on BOTH nodes (configuration, staff roster).
 * Never last-writer-wins (architecture/sync/conflict-resolution-matrix.md):
 *
 *   incoming.entityVersion == local.row_version + 1   -> apply the changes, row_version = incoming
 *   incoming.entityVersion <= local.row_version       -> STALE: not applied, CONFLICT (target category) with both payloads
 *   incoming.entityVersion >  local.row_version + 1   -> gap: DEFERRED until the intermediate change arrives
 *   row not present locally                           -> DEFERRED (its creation may still be in flight)
 *
 * The row is locked FOR UPDATE for the check-and-write. Payload shape: {"changes": {"<camelKey>": value, ...}};
 * only keys mapped in the VersionedTarget are accepted (anything else throws => FAILED, visible to IT).
 */
final class VersionedApply
{
    public static function apply(InboundEvent $event, VersionedTarget $target): ApplyResult
    {
        $changes = $event->payload['changes'] ?? null;
        if (! is_array($changes) || $changes === []) {
            throw new InvalidArgumentException('Versioned sync payload needs a non-empty "changes" object.');
        }
        $set = [];
        foreach ($changes as $key => $value) {
            if (! isset($target->columns[$key])) {
                throw new InvalidArgumentException("'{$key}' is not a syncable field of {$target->table}.");
            }
            [$column, $type] = $target->columns[$key];
            $set[$column] = self::cast($value, $type);
        }

        $id = Ids::toBinary($event->entityId);
        $row = DB::table($target->table)->where('id', $id)->lockForUpdate()->first();
        if ($row === null) {
            return ApplyResult::deferred("{$target->table} {$event->entityId} does not exist locally yet");
        }

        $local = (int) $row->{$target->versionColumn};
        $incoming = $event->entityVersion;
        if ($incoming <= $local) {
            return ApplyResult::conflict($target->category, $local, $incoming, self::snapshot($row, $target),
                "stale version: local {$target->table} is at v{$local}, incoming event is v{$incoming}; not applied");
        }
        if ($incoming > $local + 1) {
            return ApplyResult::deferred("version gap on {$target->table}: local v{$local}, incoming v{$incoming}");
        }

        DB::table($target->table)->where('id', $id)->update($set + [$target->versionColumn => $incoming]);

        return ApplyResult::applied();
    }

    /** @return array<string, mixed> current values of the syncable columns, keyed by payload key */
    public static function snapshot(object $row, VersionedTarget $target): array
    {
        $out = [];
        foreach ($target->columns as $key => [$column, $type]) {
            $v = $row->{$column} ?? null;
            $out[$key] = match ($type) {
                'uuid' => is_string($v) && strlen($v) === 16 ? Ids::fromBinary($v) : $v,
                'bool' => $v === null ? null : (bool) $v,
                'int' => $v === null ? null : (int) $v,
                'json' => is_string($v) ? json_decode($v, true) : $v,
                default => $v,
            };
        }
        $out['rowVersion'] = (int) $row->{$target->versionColumn};

        return $out;
    }

    private static function cast(mixed $v, string $type): mixed
    {
        if ($v === null) {
            return null;
        }

        return match ($type) {
            'int' => is_numeric($v) ? (int) $v : throw new InvalidArgumentException('Expected an integer.'),
            'bool' => (int) filter_var($v, FILTER_VALIDATE_BOOL),
            'uuid' => Ids::toBinary((string) $v),
            'decimal' => is_string($v) && preg_match('/^-?\d+(\.\d+)?$/', $v) ? $v : throw new InvalidArgumentException('Decimals must be strings.'),
            'json' => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'datetime' => (string) $v,
            default => (string) $v,
        };
    }
}
