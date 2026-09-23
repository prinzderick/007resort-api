<?php

namespace App\Support\Realtime;

use App\Support\Ids;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Contract realtime event (api/realtime.md): private channel, Pusher event name, envelope {eventId, occurredAt, correlationId, data}.
 * Broadcast AFTER the DB transaction commits, so a client that gets the event and calls REST always sees the change.
 * Realtime is a hint channel only — never mutate business state on it.
 *
 *   RealtimeEvent::publish("device.{$deviceId}", 'device.command', ['command' => 'FORCE_LOGOUT', 'payload' => []]);
 *   RealtimeEvent::publish("facility.{$facilityId}.orders", 'order.updated', [...]);
 */
class RealtimeEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public bool $afterCommit = true;

    public function __construct(
        public readonly string $channel,
        public readonly string $eventName,
        public readonly array $data,
        public readonly string $eventId,
        public readonly string $occurredAt,
        public readonly ?string $correlationId,
    ) {}

    /** @param  string  $channel  without the `private-` prefix, e.g. "kds.station.{id}" */
    public static function publish(string $channel, string $eventName, array $data): void
    {
        event(new self(
            $channel, $eventName, $data, Ids::uuid7(), now('UTC')->format('Y-m-d\TH:i:s.u\Z'),
            request()->attributes->get('correlation_id'),
        ));
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->channel)];
    }

    public function broadcastAs(): string
    {
        return $this->eventName;
    }

    public function broadcastWith(): array
    {
        return ['eventId' => $this->eventId, 'occurredAt' => $this->occurredAt, 'correlationId' => $this->correlationId, 'data' => $this->data];
    }
}
