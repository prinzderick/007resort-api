<?php

namespace App\Domain\Orders\Broadcast;

use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Realtime hint (api/realtime.md §3): envelope {eventId, occurredAt, correlationId, data}. Dispatched AFTER the DB
 * transaction commits (ShouldDispatchAfterCommit) and pushed immediately (no queue worker needed).
 */
abstract class RealtimeEvent implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public string $eventId;

    public string $occurredAt;

    public ?string $correlationId;

    /** @param list<string> $channels channel names WITHOUT the `private-` prefix @param array<string, mixed> $data */
    public function __construct(public array $channels, public array $data)
    {
        $this->eventId = Ids::uuid7();
        $this->occurredAt = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.u\Z');
        $this->correlationId = app()->bound('request') ? (request()->header('X-Correlation-Id') ?: null) : null;
    }

    /** Wire event name, e.g. `prep-ticket.created`. */
    abstract public static function wireName(): string;

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return array_map(fn (string $c) => new PrivateChannel($c), $this->channels);
    }

    public function broadcastAs(): string
    {
        return static::wireName();
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['eventId' => $this->eventId, 'occurredAt' => $this->occurredAt, 'correlationId' => $this->correlationId, 'data' => $this->data];
    }
}
