<?php

namespace App\Domain\Payments\Broadcast;

use App\Domain\Orders\Broadcast\RealtimeEvent;

/** Wire event `payment.confirmed` (docs/WAITER_COLLECTION.md section 9). Hint only; reload over REST. */
final class PaymentConfirmed extends RealtimeEvent
{
    public static function wireName(): string
    {
        return 'payment.confirmed';
    }
}
