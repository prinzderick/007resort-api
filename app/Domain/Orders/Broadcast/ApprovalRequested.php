<?php

namespace App\Domain\Orders\Broadcast;

/** Wire event `approval.requested` (api/realtime.md). */
final class ApprovalRequested extends RealtimeEvent
{
    public static function wireName(): string
    {
        return 'approval.requested';
    }
}
