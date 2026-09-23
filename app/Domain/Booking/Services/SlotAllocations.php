<?php

namespace App\Domain\Booking\Services;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;

final class SlotAllocations
{
    /** Delete a booking's allocation rows BY PRIMARY KEY (record locks only; no gap-lock deadlocks between concurrent releasers). */
    public static function release(string $bookingId): void
    {
        $items = DB::table('booking_item')->where('booking_id', Ids::toBinary($bookingId))->pluck('id')->all();
        if ($items === []) {
            return;
        }
        $ids = DB::table('slot_allocation')->whereIn('booking_item_id', $items)->orderBy('id')->pluck('id')->all();
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('slot_allocation')->whereIn('id', $chunk)->delete();
        }
    }

    /** @return list<int> distinct unit numbers a booking occupies */
    public static function units(string $bookingId): array
    {
        return DB::table('slot_allocation as sa')->join('booking_item as bi', 'bi.id', '=', 'sa.booking_item_id')
            ->where('bi.booking_id', Ids::toBinary($bookingId))->distinct()->orderBy('sa.unit_no')->pluck('sa.unit_no')->map(fn ($u) => (int) $u)->all();
    }
}
