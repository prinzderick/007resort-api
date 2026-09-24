<?php

namespace App\Domain\Reporting\Support;

use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Local-date -> UTC half-open range conversion using the SITE time zone (never MySQL tz tables). */
final class Period
{
    /** @return array{0: string, 1: string} UTC 'Y-m-d H:i:s.u' bounds [from, to) covering local dates $from..$to inclusive */
    public static function utcBounds(string $siteId, string $from, string $to): array
    {
        $tz = self::timeZone($siteId);
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $from.' 00:00:00', $tz)->utc();
        $end = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $to.' 00:00:00', $tz)->addDay()->utc();

        return [$start->format('Y-m-d H:i:s.u'), $end->format('Y-m-d H:i:s.u')];
    }

    public static function timeZone(string $siteId): string
    {
        return (string) (DB::table('site')->where('id', Ids::toBinary($siteId))->value('time_zone') ?: 'Africa/Lagos');
    }

    /** @param list<string> $uuids @return list<string> binary ids */
    public static function bins(array $uuids): array
    {
        return array_map(Ids::toBinary(...), $uuids);
    }

    public static function marks(array $items): string
    {
        return implode(',', array_fill(0, count($items), '?'));
    }
}
