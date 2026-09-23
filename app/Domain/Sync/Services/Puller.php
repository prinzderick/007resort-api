<?php

namespace App\Domain\Sync\Services;

use App\Domain\Sync\Support\InboundEvent;
use App\Domain\Sync\Support\InboxOutcome;
use App\Support\Ids;
use App\Support\Node;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Local-side puller: polls Cloud (Cloud never calls Local, ADR-0005), applies each event through the SAME
 * InboxProcessor as a pushed event (dedup + applier registry + one transaction), then acknowledges the results.
 * If the acknowledgement is lost, Cloud re-serves the events after the lease; the inbox dedups them (D-4).
 */
final class Puller
{
    public function __construct(private readonly PeerClient $peer, private readonly InboxProcessor $inbox) {}

    /** @return array{pulled: int, applied: int, duplicates: int, conflicts: int, failed: int, deferred: int, peerDown: bool} */
    public function run(): array
    {
        $r = ['pulled' => 0, 'applied' => 0, 'duplicates' => 0, 'conflicts' => 0, 'failed' => 0, 'deferred' => 0, 'peerDown' => false];
        $cursor = null;
        $siteId = Node::siteId();

        for ($page = 0; $page < 50; $page++) {
            try {
                $resp = $this->peer->request('GET', '/sync/pull', array_filter(['limit' => (int) config('sync.pull_batch'), 'cursor' => $cursor, 'siteId' => $siteId]));
            } catch (PeerUnreachable $e) {
                SyncState::error($e->getMessage());
                $r['peerDown'] = true;

                return $r;
            }
            if (! $resp->ok()) {
                SyncState::error("peer answered HTTP {$resp->status} to pull ".($resp->problemCode() ?? ''));
                $r['peerDown'] = true;

                return $r;
            }
            SyncState::touch(SyncState::LAST_PULL_OK);

            $results = [];
            foreach ((array) ($resp->body['items'] ?? []) as $item) {
                $r['pulled']++;
                $results[] = $this->apply($item, $r);
            }

            if ($results !== []) {
                try {
                    $ack = $this->peer->request('POST', '/sync/pull/ack', json: ['results' => array_map(fn (InboxOutcome $o) => $o->toArray(), $results)]);
                    if (! $ack->ok()) {
                        SyncState::error("peer answered HTTP {$ack->status} to pull ack");
                    }
                } catch (PeerUnreachable $e) {
                    // Applied here, ack lost: Cloud will re-serve and the inbox will answer DUPLICATE. Nothing is lost.
                    SyncState::error($e->getMessage());
                    $r['peerDown'] = true;

                    return $r;
                }
            }

            $cursor = $resp->body['nextCursor'] ?? null;
            SyncState::set(SyncState::PULL_CURSOR, $cursor);
            if ($cursor === null) {
                break;
            }
        }

        return $r;
    }

    /** @param array<string, mixed> $item @param array<string, int|bool> $r */
    private function apply(array $item, array &$r): InboxOutcome
    {
        try {
            if (($item['sourceNode'] ?? null) !== 'cloud') {
                throw new \InvalidArgumentException('pulled event has an unexpected sourceNode');
            }
            $o = $this->inbox->receive(InboundEvent::fromEnvelope($item));
        } catch (Throwable $e) {
            Log::warning('sync: pulled event could not be processed', ['eventId' => $item['eventId'] ?? null, 'error' => $e->getMessage()]);
            $o = new InboxOutcome(isset($item['eventId']) && Ids::isUuid($item['eventId']) ? Ids::normalize($item['eventId']) : Ids::uuid7(), InboxOutcome::FAILED, mb_substr($e->getMessage(), 0, 300));
        }
        match ($o->result) {
            InboxOutcome::APPLIED => $r['applied']++,
            InboxOutcome::DUPLICATE => $r['duplicates']++,
            InboxOutcome::CONFLICT => $r['conflicts']++,
            InboxOutcome::DEFERRED => $r['deferred']++,
            default => $r['failed']++,
        };

        return $o;
    }
}
