<?php

namespace App\Domain\Payments\Broadcast;

use App\Domain\Orders\Broadcast\RealtimeEvent;

/** Wire event `payment.expired` (docs/WAITER_COLLECTION.md section 9). Hint only; reload over REST. */
final class PaymentExpired extends RealtimeEvent
{
    public static function wireName(): string
    {
        return 'payment.expired';
    }
}
