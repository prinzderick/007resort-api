<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Models\BookableResource;
use App\Domain\Booking\Models\Blackout;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Availability queries: whole-resource, per-unit capacity (one allocation row per unit), fixed time slots and the
 * combined mode all read the same `slot_allocation` rows the hold path writes — there is no separate cache that can drift.
 * Expired-but-unswept holds are ignored (they are free), exactly as the hold path treats them.
 */
final class AvailabilityService
{
    public const MAX_WINDOW_DAYS = 31;

    public function __construct(private readonly SlotGrid $grid, private readonly BookingRules $rules) {}

    /** @return list<array{start: string, end: string, available: bool, remainingCapacity: int, price: string}> */
    public function slots(BookableResource $resource, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($to <= $from) {
            throw ApiProblem::unprocessable('validation_failed', '`to` must be after `from`.', ['to' => ['must be after from']]);
        }
        if ($from->diffInDays($to) > self::MAX_WINDOW_DAYS) {
            throw ApiProblem::unprocessable('validation_failed', 'The availability window may not exceed '.self::MAX_WINDOW_DAYS.' days.', ['to' => ['window too large']]);
        }
        $now = CarbonImmutable::now('UTC');
        $rules = $this->rules->for($resource);
        $earliest = $now->addMinutes((int) $rules['min_notice_minutes']);
        $latest = $now->addDays((int) $rules['max_advance_days']);
        $units = $resource->unitCount();

        $used = DB::table('slot_allocation')
            ->where('resource_id', Ids::toBinary($resource->id))
            ->where('slot_start', '>=', $from->format('Y-m-d H:i:s.u'))->where('slot_start', '<', $to->format('Y-m-d H:i:s.u'))
            ->where(fn ($q) => $q->where('status', 'CONFIRMED')->orWhereNull('hold_expires_at')->orWhere('hold_expires_at', '>', $now->format('Y-m-d H:i:s.u')))
            ->groupBy('slot_start')->selectRaw('slot_start, COUNT(*) AS n')->pluck('n', 'slot_start');

        $blackouts = Blackout::query()
            ->where('ends_at', '>', $from->format('Y-m-d H:i:s.u'))->where('starts_at', '<', $to->format('Y-m-d H:i:s.u'))
            ->where(fn ($q) => $q->where('resource_id', $resource->id)->orWhere('facility_unit_id', $resource->facility_unit_id))
            ->get();

        $out = [];
        foreach ($this->grid->slots($resource, $from, $to) as [$start, $end]) {
            $taken = (int) ($used[$start->format('Y-m-d H:i:s.u')] ?? 0);
            $remaining = max(0, $units - $taken);
            $blocked = $blackouts->contains(fn ($b) => $b->starts_at < $end && $b->ends_at > $start);
            $bookable = $start >= $earliest && $start <= $latest;
            $out[] = [
                'start' => $start->format('Y-m-d\TH:i:s\Z'),
                'end' => $end->format('Y-m-d\TH:i:s\Z'),
                'available' => $remaining > 0 && ! $blocked && $bookable,
                'remainingCapacity' => $blocked || ! $bookable ? 0 : $remaining,
                'price' => Money::of($resource->price)->amount,
            ];
        }

        return $out;
    }
}
