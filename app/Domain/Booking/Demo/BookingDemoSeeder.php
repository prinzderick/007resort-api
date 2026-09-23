<?php

namespace App\Domain\Booking\Demo;

use App\Domain\Booking\Models\BookableResource;
use App\Support\Demo\DemoContext;
use App\Support\Demo\DemoIds;
use App\Support\Demo\DemoSeeder;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * INTEGRATED demo (picked up by `php artisan r007:demo-seed` once the Organization/Identity/Devices demo seeders exist).
 * Enriches THEIR facilities and bookable resources (ids from DemoIds) with prices, slot rules, availability, catalog products, pool
 * ticket types and two sample QR entitlements. Adds NO staff, roles or resources of its own (the core demo tests count those): scanning
 * uses supervisor1 / manager1 (ticket.redeem), the Sports Store window storekeeper1 (ticket.release), Reception cashier1. Idempotent.
 *
 * Runs after Organization 10, Identity 20, Devices 30 and Catalog 100.
 */
class BookingDemoSeeder implements DemoSeeder
{
    public function priority(): int
    {
        return 110;
    }

    public function run(DemoContext $ctx): void
    {
        $f = [
            'org' => DemoIds::org(), 'site' => DemoIds::site(), 'reception' => DemoIds::facility('RECEPTION'), 'arena' => DemoIds::facility('SPORTS_ARENA'),
            'store' => DemoIds::facility('SPORTS_STORE'), 'pool' => DemoIds::facility('POOL_AREA'),
        ];
        $res = fn (string $facCode, string $code, string $name, string $mode, int $cap, string $price, string $sku, bool $clinic = false) => [
            'code' => $code, 'name' => $name, 'mode' => $mode, 'capacity' => $cap, 'price' => $price, 'sku' => $sku, 'clinic' => $clinic,
            'facility' => DemoIds::facility($facCode), 'id' => DemoIds::resource($facCode, $code),
        ];
        $data = new BookingDemoData;
        $data->seed($f, [
            $res('FOOTBALL', 'PITCH_1', 'Football Pitch 1', BookableResource::MODE_WHOLE, 1, '25000.0000', 'FEE-FOOTBALL'),
            $res('LAWN_TENNIS', 'COURT_1', 'Lawn Tennis Court 1', BookableResource::MODE_SLOT, 1, '5000.0000', 'FEE-TENNIS'),
            $res('LAWN_TENNIS', 'COURT_2', 'Lawn Tennis Court 2', BookableResource::MODE_SLOT, 1, '5000.0000', 'FEE-TENNIS'),
            $res('BASKETBALL', 'COURT_1', 'Basketball Court 1', BookableResource::MODE_SLOT, 1, '8000.0000', 'FEE-BASKETBALL'),
            // Per-seat capacity + whole-hall (combined mode) on the Organization demo's Event Hall: 300 seats, 30 reserved for offline Reception.
            $res('EVENT_CENTRE', 'HALL', 'Event Hall', BookableResource::MODE_CAPACITY, 300, '5000.0000', 'FEE-EVENT-SEAT', true),
        ], fn (string $line) => $ctx->info($line));

        $this->receptionPaysFirst($f['reception']);

        $ctx->info('  Sports Entrance / Pool gates: sign in as supervisor1 or manager1; Sports Store window: storekeeper1; Reception: cashier1 (all with PIN 1234).');
        $ctx->table('Sample QR entitlements (arena + rental items at the Sports Store; pool ticket)', ['entitlement'], array_map(fn ($s) => [$s], $data->samples));
    }

    /**
     * Reception takes payment BEFORE service (a counter): Orders' default is pay-after-service, under which Payments refuses to
     * take money for a DRAFT order — that would block the whole Reception flow (slot fee + rentals paid at the counter).
     */
    private function receptionPaysFirst(string $receptionId): void
    {
        $bin = Ids::toBinary($receptionId);
        $cap = DB::table('facility_capability')->where('facility_unit_id', $bin)->where('capability_code', 'POS')->value('id');
        if ($cap === null) {
            return;
        }
        DB::table('operating_rule')->updateOrInsert(['facility_capability_id' => $cap, 'rule_key' => 'payment_timing'], ['id' => Ids::toBinary(Ids::uuid7()), 'rule_value' => 'PAY_FIRST']);
    }
}
