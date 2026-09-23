<?php

namespace App\Domain\Sync\Services;

use App\Domain\Sync\Contracts\EntityOrdered;
use App\Domain\Sync\Support\ApplyResult;
use App\Domain\Sync\Support\Backoff;
use App\Domain\Sync\Support\InboundEvent;
use App\Domain\Sync\Support\InboxOutcome;
use App\Domain\Sync\Support\NotApplied;
use App\Domain\Sync\Support\Ts;
use App\Support\Audit\Audit;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Receiving side of the outbox/inbox pattern (architecture/sync/outbox-inbox-design.md §3-4).
 *
 * For every event, in ONE database transaction: store the envelope in `inbox_event` (event_id PRIMARY KEY = the
 * dedup key), then dispatch to the applier for its event_type inside a SAVEPOINT. Consequences:
 *   - exactly-once EFFECT: a redelivered event_id is a no-op that is still acknowledged (DUPLICATE);
 *   - applied + recorded atomically: a crash can never leave "applied but not recorded" or the reverse;
 *   - an applier that throws / returns conflict / defers rolls back ONLY its own writes; the inbox row survives
 *     (FAILED / CONFLICT / PENDING) so nothing is ever dropped and everything can be reprocessed;
 *   - unknown event types are stored and marked FAILED until an applier exists.
 */
final class InboxProcessor
{
    public function __construct(private readonly SyncApplierRegistry $registry, private readonly ConflictService $conflicts) {}

    public function receive(InboundEvent $event): InboxOutcome
    {
        $outcome = DB::transaction(function () use ($event) {
            $this->store($event);

            return $this->processLocked(Ids::toBinary($event->eventId));
        }, 3);

        if ($outcome->result === InboxOutcome::APPLIED) {
            $this->drainDeferred($event->entityType, $event->entityId);
        }

        return $outcome;
    }

    /** Re-run a stored FAILED / CONFLICT / PENDING event (admin retry, replay-failed, conflict reprocess). */
    public function reprocess(string $eventId): ?InboxOutcome
    {
        $bin = Ids::toBinary($eventId);
        $outcome = DB::transaction(function () use ($bin, $eventId) {
            $row = DB::table('inbox_event')->where('id', $bin)->lockForUpdate()->first();
            if ($row === null || $row->result === 'APPLIED') {
                return $row === null ? null : new InboxOutcome($eventId, InboxOutcome::DUPLICATE);
            }
            DB::table('inbox_event')->where('id', $bin)->update(['result' => 'PENDING', 'first_deferred_at' => null, 'next_attempt_at' => null, 'conflict_detail' => null]);

            return $this->processLocked($bin);
        }, 3);

        if ($outcome?->result === InboxOutcome::APPLIED) {
            $row = DB::table('inbox_event')->where('id', $bin)->first(['entity_type', 'entity_id']);
            $this->drainDeferred($row->entity_type, Ids::fromBinary($row->entity_id));
        }

        return $outcome;
    }

    /** Deferred events whose retry time has come (scheduler). Returns how many were applied. */
    public function reprocessDue(int $limit = 100): int
    {
        $applied = 0;
        $rows = DB::table('inbox_event')->where('result', 'PENDING')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', Ts::db()))
            ->orderBy('received_at')->limit($limit)->get(['id', 'entity_type', 'entity_id']);
        foreach ($rows as $r) {
            $o = DB::transaction(fn () => $this->processLocked($r->id), 3);
            if ($o->result === InboxOutcome::APPLIED) {
                $applied++;
                $this->drainDeferred($r->entity_type, Ids::fromBinary($r->entity_id));
            }
        }

        return $applied;
    }

    /** Apply deferred events of one entity in version order, stopping at the first that still cannot apply. */
    public function drainDeferred(string $entityType, string $entityId): void
    {
        for ($i = 0; $i < 100; $i++) {
            $row = DB::table('inbox_event')->where('entity_type', $entityType)->where('entity_id', Ids::toBinary($entityId))
                ->where('result', 'PENDING')->orderBy('entity_version')->orderBy('received_at')->first(['id']);
            if ($row === null) {
                return;
            }
            $o = DB::transaction(fn () => $this->processLocked($row->id), 3);
            if ($o->result !== InboxOutcome::APPLIED) {
                return;
            }
        }
    }

    /**
     * Insert the envelope, or take an EXCLUSIVE lock on the existing row when the event_id is already known.
     * (`INSERT IGNORE` / catching 1062 would take a SHARED lock on the duplicate and then deadlock when several
     * concurrent deliveries try to upgrade it to the FOR UPDATE that follows; ON DUPLICATE KEY UPDATE queues them.)
     */
    private function store(InboundEvent $e): void
    {
        $b = fn (?string $u) => $u === null ? null : Ids::toBinary($u);
        DB::table('inbox_event')->upsert([[
            'id' => Ids::toBinary($e->eventId),
            'event_type' => $e->eventType,
            'source_node' => $e->sourceNode,
            'entity_type' => $e->entityType,
            'entity_id' => Ids::toBinary($e->entityId),
            'entity_version' => $e->entityVersion,
            'organization_id' => $b($e->organizationId),
            'site_id' => $b($e->siteId),
            'facility_id' => $b($e->facilityId),
            'occurred_at' => Ts::db(Ts::parse($e->occurredAt)),
            'payload' => json_encode($e->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'received_at' => Ts::db(),
            'result' => 'PENDING',
        ]], ['id'], ['id']);
    }

    /** MUST run inside a transaction. Locks the inbox row, decides, records. */
    private function processLocked(string $eventIdBin): InboxOutcome
    {
        $row = DB::table('inbox_event')->where('id', $eventIdBin)->lockForUpdate()->first();
        $eventId = Ids::fromBinary($eventIdBin);
        if ($row === null) {
            throw new RuntimeException("inbox_event {$eventId} vanished while processing.");
        }
        if (in_array($row->result, ['APPLIED', 'CONFLICT'], true)) {
            return new InboxOutcome($eventId, InboxOutcome::DUPLICATE); // already decided: never re-applied
        }

        $event = InboundEvent::fromRow($row);
        $applier = $this->registry->for($event->eventType);
        if ($applier === null) {
            return $this->fail($row, "no applier registered for event type '{$event->eventType}'");
        }

        try {
            if ($applier instanceof EntityOrdered && ($early = $this->checkOrder($event, $applier)) !== null) {
                return $this->settle($row, $event, $early);
            }
            $result = DB::transaction(function () use ($applier, $event) { // savepoint
                $r = $applier->apply($event);
                if ($r->kind !== ApplyResult::APPLIED) {
                    throw new NotApplied($r); // roll back whatever the applier wrote
                }
                $this->recordApplied($event, $applier instanceof EntityOrdered);

                return $r;
            });
        } catch (NotApplied $n) {
            return $this->settle($row, $event, $n->result);
        } catch (Throwable $t) {
            Log::warning('sync: applier failed', ['eventId' => $eventId, 'eventType' => $event->eventType, 'error' => $t->getMessage()]);

            return $this->fail($row, class_basename($t).': '.$t->getMessage());
        }

        return $this->settle($row, $event, $result);
    }

    private function checkOrder(InboundEvent $event, EntityOrdered $applier): ?ApplyResult
    {
        $applied = DB::table('sync_entity_version')->where('entity_type', $event->entityType)
            ->where('entity_id', Ids::toBinary($event->entityId))->lockForUpdate()->value('applied_version');
        $expected = $applied === null ? $applier->firstEntityVersion() : ((int) $applied) + 1;
        $v = $event->entityVersion;
        if ($v === $expected || ($applied !== null && $v === (int) $applied)) {
            return null;
        }
        if ($v > $expected) {
            return ApplyResult::deferred("out of order: {$event->entityType} is at v".($applied ?? 'none').", event is v{$v}, expected v{$expected}");
        }

        return ApplyResult::conflict('ENTITY_VERSION', (int) $applied, $v, null, "event v{$v} is older than the already-applied v{$applied}; not applied");
    }

    private function recordApplied(InboundEvent $event, bool $ordered): void
    {
        if ($ordered) {
            DB::statement('INSERT INTO sync_entity_version (entity_type, entity_id, applied_version, updated_at) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE applied_version = GREATEST(applied_version, VALUES(applied_version)), updated_at = VALUES(updated_at)',
                [$event->entityType, Ids::toBinary($event->entityId), $event->entityVersion, Ts::db()]);
        }
        if (config('sync.audit_applied')) {
            Audit::record('sync.event.applied', $event->entityType, $event->entityId, null, [
                'eventId' => $event->eventId, 'eventType' => $event->eventType,
                'sourceNode' => $event->sourceNode, 'entityVersion' => $event->entityVersion,
            ], organizationId: $event->organizationId, siteId: $event->siteId, facilityUnitId: $event->facilityId);
        }
    }

    private function settle(object $row, InboundEvent $event, ApplyResult $r): InboxOutcome
    {
        $bin = $row->id;
        $now = Ts::db();
        $attempts = ((int) $row->attempts) + 1;

        if ($r->kind === ApplyResult::APPLIED) {
            DB::table('inbox_event')->where('id', $bin)->update([
                'result' => 'APPLIED', 'processed_at' => $now, 'attempts' => $attempts, 'next_attempt_at' => null, 'last_error' => null, 'conflict_detail' => null,
            ]);
            DB::table('sync_conflict')->where('event_id', $bin)->where('status', 'OPEN')->update([
                'status' => 'RESOLVED', 'resolution' => 'REPROCESSED', 'resolved_at' => $now, 'resolution_note' => 'Event applied on reprocessing.',
            ]);

            return new InboxOutcome($event->eventId, InboxOutcome::APPLIED, $r->detail);
        }

        if ($r->kind === ApplyResult::DEFERRED) {
            $first = $row->first_deferred_at;
            $escalate = $first !== null && Ts::parse($first)->addSeconds((int) config('sync.defer_escalate_after'))->lessThanOrEqualTo(Ts::now());
            if (! $escalate) {
                DB::table('inbox_event')->where('id', $bin)->update([
                    'result' => 'PENDING', 'attempts' => $attempts, 'first_deferred_at' => $first ?? $now,
                    'next_attempt_at' => Backoff::nextAt($attempts, (float) config('sync.defer_backoff_base'), (float) config('sync.defer_backoff_cap')),
                    'last_error' => json_encode(['message' => $r->detail, 'at' => Ts::iso($now)]),
                ]);

                return new InboxOutcome($event->eventId, InboxOutcome::DEFERRED, $r->detail);
            }
            $r = ApplyResult::conflict('ENTITY_VERSION', $r->localVersion, $event->entityVersion, null, 'deferred beyond the escalation window: '.$r->detail);
        }

        // CONFLICT: recorded for review, never applied, never overwritten.
        $id = $this->conflicts->record($event, $r);
        DB::table('inbox_event')->where('id', $bin)->update([
            'result' => 'CONFLICT', 'processed_at' => $now, 'attempts' => $attempts, 'next_attempt_at' => null,
            'conflict_detail' => json_encode(['conflictId' => $id, 'category' => $r->category, 'localVersion' => $r->localVersion, 'incomingVersion' => $r->incomingVersion ?? $event->entityVersion, 'detail' => $r->detail]),
        ]);

        return new InboxOutcome($event->eventId, InboxOutcome::CONFLICT, $r->detail);
    }

    private function fail(object $row, string $message): InboxOutcome
    {
        $message = mb_substr($message, 0, 500);
        DB::table('inbox_event')->where('id', $row->id)->update([
            'result' => 'FAILED', 'attempts' => ((int) $row->attempts) + 1, 'next_attempt_at' => null,
            'last_error' => json_encode(['message' => $message, 'at' => Ts::iso(Ts::db())]),
        ]);

        return new InboxOutcome(Ids::fromBinary($row->id), InboxOutcome::FAILED, $message);
    }
}
