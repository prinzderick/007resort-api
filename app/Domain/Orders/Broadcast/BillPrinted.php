<?php

namespace App\Domain\Orders\Broadcast;

/** Wire event `bill.printed` (docs/WAITER_COLLECTION.md section 9). */
final class BillPrinted extends RealtimeEvent
{
    public static function wireName(): string
    {
        return 'bill.printed';
    }
}
