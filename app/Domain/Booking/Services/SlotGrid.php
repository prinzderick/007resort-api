<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Models\AvailabilitySchedule;
use App\Domain\Booking\Models\BookableResource;
use Carbon\CarbonImmutable;

/**
 * The slot grid of a resource: opening windows (weekly schedule in property-local time, converted to UTC) cut into
 * `slot_minutes` slots anchored at each window's opening time. Every booking is a run of contiguous grid slots, so the
 * `slot_allocation` unique key (resource, unit, slot_start) is a complete overlap guard.
 */
final class SlotGrid
{
    public function timezone(): string
    {
        return config('booking.timezone', 'Africa/Lagos');
    }

    /**
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}> slots [start, end) in UTC overlapping [from, to)
     */
    public function slots(BookableResource $resource, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tz = $this->timezone();
        $step = $resource->slot_minutes;
        $out = [];
        $day = $from->setTimezone($tz)->startOfDay()->subDay();
        $lastDay = $to->setTimezone($tz)->startOfDay()->addDay();
        $schedule = AvailabilitySchedule::query()->where('resource_id', $resource->id)->get();

        for (; $day <= $lastDay; $day = $day->addDay()) {
            foreach ($this->windowsFor($schedule, $day) as [$open, $close]) {
                $start = $day->setTimeFromTimeString($open);
                $windowEnd = $day->setTimeFromTimeString($close);
                while ($start->addMinutes($step) <= $windowEnd) {
                    $s = $start->utc();
                    $e = $start->addMinutes($step)->utc();
                    if ($e > $from && $s < $to) {
                        $out[] = [$s->toImmutable(), $e->toImmutable()];
                    }
                    $start = $start->addMinutes($step);
                }
            }
        }

        return $out;
    }

    /**
     * Is [start, end) an exact run of contiguous grid slots? Returns the slot starts (UTC) or null.
     *
     * @return list<CarbonImmutable>|null
     */
    public function slotStartsFor(BookableResource $resource, CarbonImmutable $start, CarbonImmutable $end): ?array
    {
        if ($end <= $start) {
            return null;
        }
        $index = [];
        foreach ($this->slots($resource, $start, $end) as [$s, $e]) {
            $index[$s->format('Y-m-d H:i:s')] = $e;
        }
        $starts = [];
        for ($cursor = $start; $cursor < $end;) {
            $key = $cursor->format('Y-m-d H:i:s');
            if (! isset($index[$key])) {
                return null;
            }
            $starts[] = $cursor;
            $cursor = $index[$key];
        }

        return $cursor->equalTo($end) ? $starts : null;
    }

    /** @return list<array{0: string, 1: string}> [open, close] HH:MM:SS windows for this local day */
    private function windowsFor($schedule, CarbonImmutable $localDay): array
    {
        if ($schedule->isEmpty()) {
            $h = config('booking.default_hours');

            return [[$h['open'].':00', $h['close'].':00']];
        }
        $out = [];
        foreach ($schedule as $row) {
            if ((int) $row->day_of_week !== $localDay->dayOfWeekIso) {
                continue;
            }
            $d = $localDay->format('Y-m-d');
            if (($row->valid_from !== null && substr((string) $row->valid_from, 0, 10) > $d) || ($row->valid_to !== null && substr((string) $row->valid_to, 0, 10) < $d)) {
                continue;
            }
            $out[] = [(string) $row->open_time, (string) $row->close_time];
        }

        return $out;
    }
}
