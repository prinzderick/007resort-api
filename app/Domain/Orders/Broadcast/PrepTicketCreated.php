<?php

namespace App\Domain\Orders\Broadcast;

/** Wire event `prep-ticket.created` (api/realtime.md). */
final class PrepTicketCreated extends RealtimeEvent
{
    public static function wireName(): string
    {
        return 'prep-ticket.created';
    }
}
