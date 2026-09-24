<?php

namespace App\Domain\Orders\Broadcast;

/** Wire event `prep-ticket.updated` (api/realtime.md). */
final class PrepTicketUpdated extends RealtimeEvent
{
    public static function wireName(): string
    {
        return 'prep-ticket.updated';
    }
}
