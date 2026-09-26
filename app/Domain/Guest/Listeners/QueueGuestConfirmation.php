<?php

namespace App\Domain\Guest\Listeners;

use App\Domain\Guest\Services\GuestMessenger;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * Payments' `PaymentCaptured` (synchronous, inside the capture transaction): if the paid subject belongs to a guest order, queue the
 * confirmation email + SMS in the same transaction (outbox pattern). Duck-typed so this module does not hard-depend on Payments.
 */
final class QueueGuestConfirmation
{
    public function __construct(private readonly GuestMessenger $messenger) {}

    public function handle(object $event): void
    {
        $q = DB::table('guest_order')->whereNull('erased_at');
        $type = $event->subjectType ?? null;
        $subject = isset($event->subjectId) && is_string($event->subjectId) && Ids::isUuid($event->subjectId) ? Ids::toBinary($event->subjectId) : null;
        $orderIds = array_values(array_filter((array) ($event->orderIds ?? []), fn ($id) => is_string($id) && Ids::isUuid($id)));
        $q->where(function ($w) use ($type, $subject, $orderIds): void {
            $type === 'BOOKING' && $subject !== null && $w->orWhere('booking_id', $subject);
            $type === 'MEMBERSHIP' && $subject !== null && $w->orWhere('membership_id', $subject);
            $orderIds !== [] && $w->orWhereIn('order_id', array_map(Ids::toBinary(...), $orderIds));
            $w->orWhereRaw('1 = 0');
        });
        foreach ($q->pluck('id') as $id) {
            $this->messenger->queueConfirmation($id);
        }
    }
}
