<?php

namespace App\Domain\Booking\Services;

use App\Support\Node;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Human booking numbers `BK-YYYYMMDD-0007` (Cloud-issued: `BK-YYYYMMDD-C0007`, so the two nodes can never collide).
 * Atomic per (local date, node) via INSERT .. ON DUPLICATE KEY UPDATE LAST_INSERT_ID(); call it as LATE as possible in the
 * transaction — the counter row stays locked until commit.
 */
final class BookingNumbers
{
    public function next(): string
    {
        $date = CarbonImmutable::now(config('booking.timezone', 'Africa/Lagos'))->format('Y-m-d');
        $node = Node::isCloud() ? 'C' : 'L';
        DB::statement('INSERT INTO booking_number_counter (counter_date, node, seq_value) VALUES (?, ?, LAST_INSERT_ID(1))
                       ON DUPLICATE KEY UPDATE seq_value = LAST_INSERT_ID(seq_value + 1)', [$date, $node]);
        $seq = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;

        return sprintf('BK-%s-%s%04d', str_replace('-', '', $date), $node === 'C' ? 'C' : '', $seq);
    }
}
