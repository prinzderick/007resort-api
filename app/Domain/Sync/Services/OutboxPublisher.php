<?php

namespace App\Domain\Sync\Services;

use App\Domain\Sync\Support\InboxOutcome;
use App\Domain\Sync\Support\Ts;
use App\Support\Ids;
use App\Support\Node;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Drains `outbox_event` to the peer (Local -> Cloud push). At-least-once delivery, strict per-entity order,
 * batching, exponential backoff with jitter. MySQL is the queue; Redis merely wakes this up, so a dead
 * Redis / worker delays sync but can never lose an event (architecture/24, D-9).
 *
 * One publisher at a time per database (MySQL GET_LOCK — connection-scoped, so a crashed worker releases it).
 */
final class OutboxPublisher
{
    public function __construct(private readonly PeerClient $peer, private readonly OutboxDelivery $delivery) {}

    /** @return array{skipped: bool, sent: int, synced: int, conflicts: int, deferred: int, failed: int, peerDown: bool} */
    public function run(): array
    {
        $report = ['skipped' => false, 'sent' => 0, 'synced' => 0, 'conflicts' => 0, 'deferred' => 0, 'failed' => 0, 'peerDown' => false];
        $lock = 'r007:sync:publish:'.DB::connection()->getDatabaseName();
        if ((int) (DB::selectOne('SELECT GET_LOCK(?, 0) AS l', [$lock])->l ?? 0) !== 1) {
            $report['skipped'] = true; // another publisher is draining

            return $report;
        }

        try {
            // Holding the lock => nothing of ours is genuinely in flight; SYNCING rows are leftovers of a crashed run.
            DB::table('outbox_event')->where('sync_status', 'SYNCING')->update(['sync_status' => 'QUEUED']);
            $this->markQueued();

            for ($i = 0; $i < (int) config('sync.max_batches_per_run'); $i++) {
                $batch = OutboxQuery::claim((int) config('sync.batch_size'));
                if ($batch === []) {
                    break;
                }
                if (! $this->deliver($batch, $report)) {
                    $report['peerDown'] = true;
                    break;
                }
            }
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS l', [$lock]);
        }

        return $report;
    }

    /** LOCAL -> QUEUED (the scheduler/dispatcher has accepted responsibility for them). */
    public function markQueued(): int
    {
        return DB::table('outbox_event')->where('sync_status', 'LOCAL')->update(['sync_status' => 'QUEUED']);
    }

    /** @param list<object> $batch @param array<string, mixed> $report @return bool false when the peer is unavailable (stop this run) */
    private function deliver(array $batch, array &$report): bool
    {
        $report['sent'] += count($batch);
        $bins = array_map(fn ($r) => $r->id, $batch);

        try {
            $resp = $this->peer->request('POST', '/sync/inbox', json: ['events' => array_map($this->envelope(...), $batch)]);
        } catch (PeerUnreachable $e) {
            $this->delivery->transportFailure($bins, $e->getMessage());

            return false;
        }

        if ($resp->status === 200) {
            $this->applyResults($batch, (array) ($resp->body['results'] ?? []), $report);
            SyncState::touch(SyncState::LAST_PUSH_OK);

            return true;
        }
        if (in_array($resp->status, [400, 422], true)) {
            // The peer refused the request as malformed: isolate the poison event(s) by sending one at a time.
            if (count($batch) > 1) {
                foreach ($batch as $one) {
                    if (! $this->deliver([$one], $report)) {
                        return false;
                    }
                }
                $report['sent'] -= count($batch); // counted per event already

                return true;
            }
            $this->delivery->record($batch[0]->id, InboxOutcome::FAILED, 'peer rejected event: HTTP '.$resp->status.' '.($resp->problemCode() ?? ''));
            $report['failed']++;

            return true;
        }

        // 401/403 (credential rejected), 404 (misconfigured URL / inbox closed), 429, 5xx: peer-side or config problem.
        // Back off and keep everything; surface it in status.
        $this->delivery->transportFailure($bins, "peer answered HTTP {$resp->status} ".($resp->problemCode() ?? ''), $resp->retryAfter);
        Log::warning('sync: peer refused push', ['status' => $resp->status, 'code' => $resp->problemCode()]);

        return false;
    }

    /** @param list<object> $batch @param list<array<string, mixed>> $results @param array<string, mixed> $report */
    private function applyResults(array $batch, array $results, array &$report): void
    {
        $byId = [];
        foreach ($results as $r) {
            if (isset($r['eventId'])) {
                $byId[Ids::normalize($r['eventId'])] = $r;
            }
        }
        foreach ($batch as $row) {
            $r = $byId[Ids::fromBinary($row->id)] ?? null;
            if ($r === null) {
                $this->delivery->transportFailure([$row->id], 'peer response did not acknowledge this event');

                continue;
            }
            $result = (string) ($r['result'] ?? InboxOutcome::FAILED);
            $this->delivery->record($row->id, $result, $r['detail'] ?? null);
            match ($result) {
                InboxOutcome::APPLIED, InboxOutcome::DUPLICATE => $report['synced']++,
                InboxOutcome::CONFLICT => $report['conflicts']++,
                InboxOutcome::DEFERRED => $report['deferred']++,
                default => $report['failed']++,
            };
        }
    }

    /** @return array<string, mixed> contract SyncEvent */
    public static function envelope(object $r): array
    {
        $u = fn (?string $b) => $b === null ? null : Ids::fromBinary($b);
        $payload = json_decode($r->payload, true);

        return [
            'eventId' => Ids::fromBinary($r->id),
            'eventType' => $r->event_type,
            'entityType' => $r->entity_type,
            'entityId' => Ids::fromBinary($r->entity_id),
            'entityVersion' => (int) $r->entity_version,
            'organizationId' => $u($r->organization_id),
            'siteId' => $u($r->site_id),
            'facilityId' => $u($r->facility_id),
            'sourceNode' => Node::name(),
            'occurredAt' => Ts::iso($r->created_at),
            'payload' => $payload === [] || $payload === null ? new \stdClass : $payload,
        ];
    }
}
