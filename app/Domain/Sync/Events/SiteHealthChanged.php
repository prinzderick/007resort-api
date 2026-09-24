<?php

namespace App\Domain\Sync\Events;

use App\Support\Ids;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `site.health` on `private-site.status` (api/realtime.md §4). Local emits it as its own health (cloudLink check)
 * and Cloud emits it when a site flips ONLINE/OFFLINE. Broadcast is a HINT: failures are logged, never thrown.
 */
final class SiteHealthChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param array<string, mixed> $data */
    public function __construct(public readonly array $data, public readonly string $eventId = '') {}

    /** @param array<string, mixed> $data */
    public static function announce(array $data): void
    {
        try {
            event(new self($data, Ids::uuid7()));
        } catch (Throwable $e) {
            Log::warning('sync: site.health broadcast failed', ['error' => $e->getMessage()]);
        }
    }

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('site.status')];
    }

    public function broadcastAs(): string
    {
        return 'site.health';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'eventId' => $this->eventId,
            'occurredAt' => now('UTC')->format('Y-m-d\TH:i:s.u\Z'),
            'correlationId' => request()->header('X-Correlation-Id'),
            'data' => $this->data + ['serverTime' => now('UTC')->format('Y-m-d\TH:i:s.u\Z')],
        ];
    }
}
