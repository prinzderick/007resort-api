<?php

namespace App\Domain\Sync\Services;

use App\Domain\Sync\Support\Backoff;
use App\Domain\Sync\Support\InboxOutcome;
use App\Domain\Sync\Support\Ts;
use Illuminate\Support\Facades\DB;

/**
 * The outbox state machine (LOCAL -> QUEUED -> SYNCING -> SYNCED | FAILED | CONFLICT), shared by the push
 * publisher and by pull acknowledgements so both directions behave identically.
 *
 *   APPLIED / DUPLICATE  -> SYNCED
 *   CONFLICT             -> CONFLICT  (receiver evaluated + recorded it; needs a human, not a retry)
 *   DEFERRED             -> QUEUED with backoff (receiver is waiting for an earlier version)
 *   FAILED               -> QUEUED with backoff; terminal FAILED after sync.max_attempts (poison event)
 *   transport failure    -> QUEUED with backoff, NEVER terminal (an outage must not fail business events)
 */
final class OutboxDelivery
{
    public function record(string $eventIdBin, string $result, ?string $detail = null): void
    {
        $row = DB::table('outbox_event')->where('id', $eventIdBin)->first(['retry_count', 'sync_status']);
        if ($row === null || $row->sync_status === 'SYNCED') {
            return;
        }
        $now = Ts::db();
        $retries = (int) $row->retry_count;
        $error = fn (string $kind) => json_encode(['result' => $kind, 'message' => $detail, 'at' => Ts::iso($now)], JSON_UNESCAPED_SLASHES);

        $update = match ($result) {
            InboxOutcome::APPLIED, InboxOutcome::DUPLICATE => ['sync_status' => 'SYNCED', 'synced_at' => $now, 'next_retry_at' => null, 'last_error' => null],
            InboxOutcome::CONFLICT => ['sync_status' => 'CONFLICT', 'next_retry_at' => null, 'last_error' => $error('CONFLICT')],
            InboxOutcome::DEFERRED => [
                'sync_status' => 'QUEUED', 'retry_count' => $retries + 1,
                'next_retry_at' => Backoff::nextAt($retries + 1, (float) config('sync.defer_backoff_base'), (float) config('sync.defer_backoff_cap')),
                'last_error' => $error('DEFERRED'),
            ],
            default => ($retries + 1 >= (int) config('sync.max_attempts'))
                ? ['sync_status' => 'FAILED', 'retry_count' => $retries + 1, 'next_retry_at' => null, 'last_error' => $error('FAILED')]
                : [
                    'sync_status' => 'QUEUED', 'retry_count' => $retries + 1,
                    'next_retry_at' => Backoff::nextAt($retries + 1, (float) config('sync.backoff_base'), (float) config('sync.backoff_cap')),
                    'last_error' => $error('FAILED'),
                ],
        };
        DB::table('outbox_event')->where('id', $eventIdBin)->update($update + ['last_attempt_at' => $now]);
    }

    /**
     * The peer could not be reached / answered 5xx / 429 / rejected our credential: back the events off, keep them.
     *
     * @param  list<string>  $eventIdBins
     */
    public function transportFailure(array $eventIdBins, string $message, ?int $retryAfter = null): void
    {
        $now = Ts::db();
        foreach ($eventIdBins as $bin) {
            $retries = (int) DB::table('outbox_event')->where('id', $bin)->value('retry_count');
            $delay = Backoff::seconds($retries + 1, (float) config('sync.backoff_base'), (float) config('sync.backoff_cap'));
            if ($retryAfter !== null) {
                $delay = max($delay, min($retryAfter, 3600));
            }
            DB::table('outbox_event')->where('id', $bin)->whereIn('sync_status', ['SYNCING', 'QUEUED', 'LOCAL'])->update([
                'sync_status' => 'QUEUED', 'retry_count' => $retries + 1, 'last_attempt_at' => $now,
                'next_retry_at' => Ts::db(Ts::now()->addMilliseconds((int) ($delay * 1000))),
                'last_error' => json_encode(['result' => 'UNREACHABLE', 'message' => mb_substr($message, 0, 300), 'at' => Ts::iso($now)], JSON_UNESCAPED_SLASHES),
            ]);
        }
        SyncState::error($message);
    }
}
