<?php

namespace App\Domain\Orders\Broadcast;

/** Wire event `order.updated` (api/realtime.md). */
final class OrderUpdated extends RealtimeEvent
{
    public static function wireName(): string
    {
        return 'order.updated';
    }
}
