<?php

namespace App\Domain\Orders\Broadcast;

/** Wire event `order.ready` (api/realtime.md). */
final class OrderReady extends RealtimeEvent
{
    public static function wireName(): string
    {
        return 'order.ready';
    }
}
