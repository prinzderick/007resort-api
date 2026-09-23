<?php

namespace App\Domain\Orders\Broadcast;

/** Wire event `table.updated` (api/realtime.md). */
final class TableUpdated extends RealtimeEvent
{
    public static function wireName(): string
    {
        return 'table.updated';
    }
}
