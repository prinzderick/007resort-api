<?php

namespace App\Domain\Payments\Broadcast;

use App\Domain\Orders\Broadcast\RealtimeEvent;

/** Wire event `cash-handover.received` (docs/WAITER_COLLECTION.md section 9). Hint only; reload over REST. */
final class CashHandoverReceived extends RealtimeEvent
{
    public static function wireName(): string
    {
        return 'cash-handover.received';
    }
}
