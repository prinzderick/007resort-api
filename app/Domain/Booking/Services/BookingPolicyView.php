<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Models\Booking;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * The `policy` object of a booking: what THE ENGINE would allow right now (same rules BookingService::cancel/reschedule enforce),
 * so clients render buttons and refund promises without re-implementing any rule.
 */
final class BookingPolicyView
{
    /** @return array{canCancel: bool, canReschedule: bool, cancelBy: ?string, rescheduleBy: ?string, refundAmount: string, cancellationFee: string, reschedulesLeft: int, note: ?string} */
    public static function for(Booking $b): array
    {
        $b->loadMissing('resource');
        $rules = app(BookingRules::class)->for($b->resource);
        $now = CarbonImmutable::now('UTC');
        $live = in_array($b->status, [Booking::HELD, Booking::PENDING_PAYMENT, Booking::CONFIRMED, Booking::RESCHEDULED], true);
        $paid = in_array($b->status, [Booking::CONFIRMED, Booking::RESCHEDULED], true);
        $started = $b->start_at <= $now;
        $cutoff = $b->start_at->subMinutes((int) $rules['cancel_cutoff_minutes']);
        $inside = $paid && $b->start_at < $now->addMinutes((int) $rules['cancel_cutoff_minutes']);
        $fee = $inside ? Money::of($b->amount_paid)->percent($rules['cancel_fee_percent'])->amount : '0.0000';
        $refund = $paid ? Money::of($b->amount_paid)->sub(Money::of($fee))->amount : '0.0000';
        $reschedCut = $b->start_at->subMinutes((int) $rules['reschedule_cutoff_minutes']);
        $left = max(0, (int) $rules['max_reschedules'] - (int) $b->reschedule_count);
        $canCancel = $live && ! $started;
        $canResched = $b->status === Booking::CONFIRMED ? ($now < $reschedCut && $left > 0)
            : ($b->status === Booking::HELD && $b->isHoldLive());
        $note = null;
        if ($canCancel && $paid) {
            $note = $inside && Money::of($fee)->compare(Money::of('0')) > 0
                ? "Cancelling now keeps a {$rules['cancel_fee_percent']}% fee; free cancellation ended ".$cutoff->format('Y-m-d\TH:i:s\Z').'.'
                : 'Free cancellation until '.$cutoff->format('Y-m-d\TH:i:s\Z').'.';
        } elseif ($paid && ! $canResched && ! $started) {
            $note = $left === 0 ? 'The maximum number of reschedules was reached.' : 'Too close to the start time to reschedule.';
        }

        return [
            'canCancel' => $canCancel, 'canReschedule' => $canResched,
            'cancelBy' => $paid ? $cutoff->format('Y-m-d\TH:i:s\Z') : null, 'rescheduleBy' => $paid ? $reschedCut->format('Y-m-d\TH:i:s\Z') : null,
            'refundAmount' => $canCancel ? $refund : '0.0000', 'cancellationFee' => $canCancel ? $fee : '0.0000', 'reschedulesLeft' => $left, 'note' => $note,
        ];
    }
}
