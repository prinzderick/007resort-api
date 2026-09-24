<?php

namespace App\Domain\Booking\Demo;

use App\Domain\Booking\Models\AvailabilitySchedule;
use App\Domain\Booking\Models\BookingRule;
use App\Domain\Ticketing\Models\Entitlement;
use App\Domain\Ticketing\Models\TicketType;
use App\Domain\Ticketing\Services\EntitlementService;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The demo data itself (idempotent), independent of HOW facilities/people are created:
 *  - standalone (this module alone): Booking\Seeders\BookingDemoSeeder creates RECEPTION / SPORTS_ARENA ... and calls this;
 *  - integrated (Organization + Identity + Devices demo seeders present): Booking\Demo\BookingDemoSeeder maps onto THEIR
 *    facility ids and resource ids and calls this.
 *
 * $facilities = ['org','site','reception','arena','store','pool'] (ids); $resources = list of defs:
 *   ['code','name','mode','capacity','price','facility','id'?,'sku','feeName','clinic'?]
 */
final class BookingDemoData
{
    /** @var list<string> */
    public array $samples = [];

    /**
     * @param  array<string, string>  $f
     * @param  list<array<string, mixed>>  $resources
     * @param  callable(string): void|null  $log
     */
    public function seed(array $f, array $resources, ?callable $log = null): void
    {
        $log ??= static function (string $line): void {};
        DB::transaction(function () use ($f, $resources, $log) {
            $catalog = $this->catalog($f);
            foreach ($resources as $d) {
                $this->resource($f, $d, $catalog);
            }
            $arenaId = $f['arena'];
            if (BookingRule::query()->where('facility_unit_id', $arenaId)->doesntExist()) {
                BookingRule::create(['organization_id' => $f['org'], 'facility_unit_id' => $arenaId, 'hold_ttl_seconds' => 600, 'cancel_cutoff_minutes' => 120, 'reschedule_cutoff_minutes' => 120, 'max_reschedules' => 2, 'early_entry_minutes' => 15]);
            }
            foreach ([['POOL-ADULT', 'Pool - Adult'], ['POOL-CHILD', 'Pool - Child']] as [$code, $name]) {
                $type = TicketType::query()->where('site_id', $f['site'])->where('code', $code)->first() ?? TicketType::create([
                    'organization_id' => $f['org'], 'site_id' => $f['site'], 'facility_unit_id' => $f['pool'], 'code' => $code, 'name' => $name,
                    'format' => 'INDIVIDUAL', 'validation_mode' => 'SINGLE_USE', 'validity_kind' => 'ISSUE_DAY',
                ]);
                if (isset($catalog['tickets'][$code])) {
                    DB::table('ticket_type')->where('id', Ids::toBinary($type->id))->update(['product_id' => Ids::toBinary($catalog['tickets'][$code])]);
                }
            }
            // The catalog's generic "Pool Day Pass" (TK-POOL) also issues a pool ticket.
            if (($pass = $catalog['existing']['TK-POOL'] ?? null) !== null && TicketType::query()->where('site_id', $f['site'])->where('code', 'POOL-DAY')->doesntExist()) {
                TicketType::create([
                    'organization_id' => $f['org'], 'site_id' => $f['site'], 'facility_unit_id' => $f['pool'], 'product_id' => $pass, 'code' => 'POOL-DAY', 'name' => 'Pool day pass',
                    'format' => 'INDIVIDUAL', 'validation_mode' => 'SINGLE_USE', 'validity_kind' => 'ISSUE_DAY',
                ]);
            }
            $this->samples($f, $log);
        });
    }

    /** Products for the Reception flow (only when the Catalog tables exist). @return array{fees: array<string, string>, tickets: array<string, string>, existing: array<string, string>} */
    private function catalog(array $f): array
    {
        $out = ['fees' => [], 'tickets' => [], 'existing' => []];
        if (! Schema::hasTable('product') || ! Schema::hasTable('price_list')) {
            return $out;
        }
        $org = Ids::toBinary($f['org']);
        $cat = DB::table('product_category')->where('organization_id', $org)->where('name', 'Tickets & Fees')->value('id')
            ?? DB::table('product_category')->where('organization_id', $org)->where('name', 'Sports & Pool')->value('id')
            ?? tap(Ids::toBinary(Ids::uuid7()), fn ($id) => DB::table('product_category')->insert(['id' => $id, 'organization_id' => $org, 'name' => 'Sports & Pool', 'sort_order' => 165]));
        $list = DB::table('price_list')->where('organization_id', $org)->where('is_default', 1)->value('id')
            ?? tap(Ids::toBinary(Ids::uuid7()), fn ($id) => DB::table('price_list')->insert(['id' => $id, 'organization_id' => $org, 'name' => 'Standard', 'is_default' => 1]));

        $sell = function (string $sku, string $name, string $kind, string $price, bool $atStore = false) use ($org, $cat, $list, $f): string {
            $id = DB::table('product')->where('organization_id', $org)->where('sku', $sku)->value('id');
            if ($id === null) {
                $id = Ids::toBinary(Ids::uuid7());
                DB::table('product')->insert(['id' => $id, 'organization_id' => $org, 'category_id' => $cat, 'sku' => $sku, 'name' => $name, 'kind' => $kind]);
                DB::table('price')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'price_list_id' => $list, 'product_id' => $id, 'amount' => $price]);
            }
            foreach (array_filter([$f['reception'], $atStore ? $f['store'] : null]) as $fac) {
                DB::table('product_facility')->insertOrIgnore(['product_id' => $id, 'facility_unit_id' => Ids::toBinary($fac)]);
            }

            return Ids::fromBinary($id);
        };

        foreach (['FEE-FOOTBALL' => ['Football pitch (per hour)', '25000'], 'FEE-TENNIS' => ['Lawn tennis court (per hour)', '5000'], 'FEE-BASKETBALL' => ['Basketball court (per hour)', '8000'], 'FEE-TENNIS-CLINIC' => ['Tennis clinic (per seat)', '3000'], 'FEE-EVENT-SEAT' => ['Event hall (per seat)', '5000']] as $sku => [$name, $price]) {
            $out['fees'][$sku] = $sell($sku, $name, 'FEE', $price);
        }
        $out['tickets']['POOL-ADULT'] = $sell('POOL-ADULT', 'Pool - Adult', 'TICKET', '3000');
        $out['tickets']['POOL-CHILD'] = $sell('POOL-CHILD', 'Pool - Child', 'TICKET', '1500');
        $sell('RENTAL-RACKET', 'Tennis racket hire', 'RENTAL', '1500');
        $sell('RENTAL-FOOTBALL', 'Football hire', 'RENTAL', '1000');
        $sell('RENTAL-BASKETBALL', 'Basketball hire', 'RENTAL', '800');
        $sell('GOODS-TENNIS-BALLS', 'Tennis balls (tube)', 'GOOD', '2500', atStore: true);
        $sell('GOODS-SPORTS-DRINK', 'Sports drink', 'GOOD', '700', atStore: true);
        if (($tk = DB::table('product')->where('organization_id', $org)->where('sku', 'TK-POOL')->value('id')) !== null) {
            $out['existing']['TK-POOL'] = Ids::fromBinary($tk);
        }

        return $out;
    }

    /** @param array<string, mixed> $d */
    private function resource(array $f, array $d, array $catalog): void
    {
        $attrs = [
            'mode' => $d['mode'], 'capacity' => $d['capacity'], 'slot_minutes' => 60, 'max_slots_per_booking' => 4, 'price' => $d['price'],
            'allow_whole_resource' => $d['clinic'] ?? false ? 1 : 0, 'local_reserve_units' => $d['clinic'] ?? false ? max(2, intdiv($d['capacity'], 10)) : 0,
            'product_id' => isset($d['sku'], $catalog['fees'][$d['sku']]) ? Ids::toBinary($catalog['fees'][$d['sku']]) : null,
        ];
        $existing = null;
        if (! empty($d['id'])) {
            $existing = DB::table('bookable_resource')->where('id', Ids::toBinary($d['id']))->first();
        }
        $existing ??= DB::table('bookable_resource')->where('site_id', Ids::toBinary($f['site']))->where('code', $d['code'])->first();
        if ($existing === null) {
            $id = $d['id'] ?? Ids::uuid7();
            DB::table('bookable_resource')->insert($attrs + [
                'id' => Ids::toBinary($id), 'organization_id' => Ids::toBinary($f['org']), 'site_id' => Ids::toBinary($f['site']), 'facility_unit_id' => Ids::toBinary($d['facility']),
                'code' => $d['code'], 'name' => $d['name'],
            ]);
        } else {
            $id = Ids::fromBinary($existing->id);
            DB::table('bookable_resource')->where('id', $existing->id)->update($attrs + ['is_active' => 1]);
        }
        if (AvailabilitySchedule::query()->where('resource_id', $id)->doesntExist()) {
            foreach (range(1, 7) as $dow) {
                AvailabilitySchedule::create(['resource_id' => $id, 'day_of_week' => $dow, 'open_time' => '07:00:00', 'close_time' => '21:00:00']);
            }
        }
    }

    /** Ready-made entitlements so Entrance/Store can be demoed before the Reception order flow exists. */
    private function samples(array $f, callable $log): void
    {
        $svc = app(EntitlementService::class);
        $now = CarbonImmutable::now('UTC');
        if (Entitlement::query()->where('source_key', 'demo:arena-rental')->doesntExist()) {
            $svc->issue('demo:arena-rental', $f['org'], $f['site'], [
                ['kind' => 'ACCESS', 'name' => 'Lawn Tennis Court 1 - Demo', 'qty' => 1, 'facilityUnitId' => $f['arena'], 'validationMode' => 'SINGLE_USE', 'validFrom' => $now->subHour(), 'validUntil' => $now->addDays(30)],
                ['kind' => 'RENTAL', 'name' => 'Racket x2', 'qty' => 2, 'facilityUnitId' => $f['store'], 'validationMode' => 'NONE'],
                ['kind' => 'GOODS', 'name' => 'Tennis balls (tube)', 'qty' => 1, 'facilityUnitId' => $f['store'], 'validationMode' => 'NONE'],
            ], holderName: 'Demo Guest');
        }
        if (Entitlement::query()->where('source_key', 'demo:pool-adult')->doesntExist()) {
            $type = TicketType::query()->where('site_id', $f['site'])->where('code', 'POOL-ADULT')->first();
            $svc->issue('demo:pool-adult', $f['org'], $f['site'], [
                ['kind' => 'ACCESS', 'name' => 'Pool - Adult', 'qty' => 1, 'facilityUnitId' => $f['pool'], 'ticketTypeId' => $type?->id, 'validationMode' => 'SINGLE_USE', 'validFrom' => $now->subHour(), 'validUntil' => $now->addDays(30)],
            ], holderName: 'Demo Swimmer');
        }
        foreach (['demo:arena-rental' => 'arena+rental', 'demo:pool-adult' => 'pool'] as $key => $label) {
            $e = Entitlement::query()->where('source_key', $key)->first();
            $this->samples[] = "sample {$label} entitlement {$e->id}  qrToken={$e->qr_token}";
            $log("  sample {$label} entitlement {$e->id}  qrToken={$e->qr_token}");
        }
    }
}
