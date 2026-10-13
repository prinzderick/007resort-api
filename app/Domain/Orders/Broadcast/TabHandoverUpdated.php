<?php

namespace App\Domain\Orders\Broadcast;

/** Wire event `tab-handover.updated`: a waiter-to-waiter hand-over was requested / accepted / rejected / cancelled / forced. */
final class TabHandoverUpdated extends RealtimeEvent
{
    public static function wireName(): string
    {
        return 'tab-handover.updated';
    }
}
