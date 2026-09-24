<?php

namespace App\Domain\Cms\Support;

use Carbon\CarbonImmutable;

/** Simple recurrence (NONE / WEEKLY until a date) expanded on read. Weekly occurrences keep the same LOCAL wall-clock time. */
final class Recurrence
{
    /**
     * Next `$limit` occurrences that have not ended before `$from`.
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable}> UTC instants
     */
    public static function next(object $event, CarbonImmutable $from, int $limit): array
    {
        $tz = Cms::tz();
        $start = Rows::carbon($event->starts_at)->setTimezone($tz);
        $end = Rows::carbon($event->ends_at)->setTimezone($tz);
        $out = [];

        if ($event->recurrence !== 'WEEKLY') {
            if ($end->utc()->gte($from)) {
                $out[] = ['start' => $start->utc(), 'end' => $end->utc()];
            }

            return $out;
        }

        $until = $event->recurrence_until !== null ? CarbonImmutable::parse($event->recurrence_until, $tz)->endOfDay() : null;
        $k = 0;
        if ($end->utc()->lt($from)) {
            $k = (int) ceil(($from->getTimestamp() - $end->getTimestamp()) / 604800);
        }
        for ($guard = 0; count($out) < $limit && $guard < 2000; $k++, $guard++) {
            $s = $start->addWeeks($k);
            if ($until !== null && $s->gt($until)) {
                break;
            }
            $e = $end->addWeeks($k);
            if ($e->utc()->lt($from)) {
                continue;
            }
            $out[] = ['start' => $s->utc(), 'end' => $e->utc()];
        }

        return $out;
    }

    /** True when no occurrence is left (used for `upcoming=false`). */
    public static function isOver(object $event, CarbonImmutable $now): bool
    {
        return self::next($event, $now, 1) === [];
    }
}
