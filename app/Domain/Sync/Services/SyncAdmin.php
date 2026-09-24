<?php

namespace App\Domain\Sync\Services;

use App\Domain\Sync\Support\InboxOutcome;
use App\Domain\Sync\Support\Ts;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Controlled retry / reprocess / resolve actions for IT/Admin (heartbeat-and-node-health §4). Every mutation is
 * audited in the same transaction. Nothing here deletes or edits a business event or a financial row.
 */
final class SyncAdmin
{
    public function __construct(private readonly InboxProcessor $inbox) {}

    /** Terminal-FAILED outbox event -> QUEUED, fresh retry budget. */
    public function retryOutbox(string $eventId): void
    {
        $bin = Ids::toBinary($eventId);
        DB::transaction(function () use ($bin, $eventId) {
            $row = DB::table('outbox_event')->where('id', $bin)->lockForUpdate()->first();
            if ($row === null) {
                throw ApiProblem::notFound('outbox_event_not_found', 'No such outbox event.');
            }
            if ($row->sync_status !== 'FAILED') {
                throw ApiProblem::conflict('outbox_event_not_retryable', "Only FAILED events can be retried (this one is {$row->sync_status}).");
            }
            DB::table('outbox_event')->where('id', $bin)->update(['sync_status' => 'QUEUED', 'retry_count' => 0, 'next_retry_at' => null]);
            Audit::record('sync.outbox.retry', 'OutboxEvent', $eventId, ['syncStatus' => 'FAILED', 'retryCount' => (int) $row->retry_count], ['syncStatus' => 'QUEUED', 'retryCount' => 0]);
        });
    }

    /** @return int events re-queued */
    public function replayFailedOutbox(): int
    {
        $n = 0;
        foreach (DB::table('outbox_event')->where('sync_status', 'FAILED')->orderBy('seq')->pluck('id') as $bin) {
            $this->retryOutbox(Ids::fromBinary($bin));
            $n++;
        }

        return $n;
    }

    /** Re-run a stored inbox event (FAILED / CONFLICT / deferred). Audited. */
    public function reprocessInbox(string $eventId): InboxOutcome
    {
        $row = DB::table('inbox_event')->where('id', Ids::toBinary($eventId))->first();
        if ($row === null) {
            throw ApiProblem::notFound('inbox_event_not_found', 'No such inbox event.');
        }
        $outcome = $this->inbox->reprocess($eventId);
        DB::transaction(fn () => Audit::record('sync.inbox.reprocess', 'InboxEvent', $eventId, ['result' => $row->result], ['result' => $outcome->result]));

        return $outcome;
    }

    /** @return array{reprocessed: int, applied: int} */
    public function replayFailedInbox(): array
    {
        $n = $ok = 0;
        foreach (DB::table('inbox_event')->where('result', 'FAILED')->orderBy('received_at')->pluck('id') as $bin) {
            $n++;
            if ($this->reprocessInbox(Ids::fromBinary($bin))->result === InboxOutcome::APPLIED) {
                $ok++;
            }
        }

        return ['reprocessed' => $n, 'applied' => $ok];
    }

    /** Re-run the applier for the conflicting event; it auto-resolves as REPROCESSED if it now applies. */
    public function reprocessConflict(string $conflictId): array
    {
        $c = $this->conflict($conflictId);
        if ($c->status !== 'OPEN') {
            throw ApiProblem::conflict('conflict_already_resolved', 'This conflict is already resolved.');
        }
        if ($c->event_id === null) {
            throw ApiProblem::unprocessable('conflict_not_reprocessable', 'This conflict has no stored event.');
        }
        $outcome = $this->reprocessInbox(Ids::fromBinary($c->event_id));

        return ['outcome' => $outcome->toArray(), 'conflict' => ConflictService::present($this->conflict($conflictId))];
    }

    /**
     * Human decision on an open conflict. KEEP_LOCAL = the receiver's version stands, incoming discarded;
     * MANUAL = the manager re-applied/handled the intended change by hand against the current version;
     * DISMISSED = no action needed. The incoming event is NEVER force-applied here.
     */
    public function resolveConflict(string $conflictId, string $resolution, string $note): array
    {
        return DB::transaction(function () use ($conflictId, $resolution, $note) {
            $bin = Ids::toBinary($conflictId);
            $c = DB::table('sync_conflict')->where('id', $bin)->lockForUpdate()->first();
            if ($c === null) {
                throw ApiProblem::notFound('conflict_not_found', 'No such conflict.');
            }
            if ($c->status !== 'OPEN') {
                throw ApiProblem::conflict('conflict_already_resolved', 'This conflict is already resolved.');
            }
            $staff = RequestContext::staffId();
            DB::table('sync_conflict')->where('id', $bin)->update([
                'status' => 'RESOLVED', 'resolution' => $resolution, 'resolution_note' => $note,
                'resolved_at' => Ts::db(), 'resolved_by_staff_id' => $staff === null ? null : Ids::toBinary($staff),
            ]);
            Audit::record('sync.conflict.resolve', 'SyncConflict', $conflictId,
                ['status' => 'OPEN'], ['status' => 'RESOLVED', 'resolution' => $resolution, 'note' => $note, 'category' => $c->category, 'eventId' => $c->event_id ? Ids::fromBinary($c->event_id) : null]);

            return ConflictService::present(DB::table('sync_conflict')->where('id', $bin)->first());
        });
    }

    private function conflict(string $id): object
    {
        $c = DB::table('sync_conflict')->where('id', Ids::toBinary($id))->first();
        if ($c === null) {
            throw ApiProblem::notFound('conflict_not_found', 'No such conflict.');
        }

        return $c;
    }
}
