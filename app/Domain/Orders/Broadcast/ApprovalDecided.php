<?php

namespace App\Domain\Orders\Broadcast;

/** Wire event `approval.decided` (api/realtime.md). */
final class ApprovalDecided extends RealtimeEvent
{
    public static function wireName(): string
    {
        return 'approval.decided';
    }
}
