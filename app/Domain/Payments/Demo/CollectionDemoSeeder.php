<?php

namespace App\Domain\Payments\Demo;

use App\Support\Demo\DemoContext;
use App\Support\Demo\DemoIds;
use App\Support\Demo\DemoSeeder;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * DEV-ONLY demo data for waiter collection (docs/WAITER_COLLECTION.md): one manual bank POS machine per table-service facility and
 * two contrasting cash policies so UIs can exercise both paths:
 *   RESTAURANT / INDOOR_CLUB / BUSH_BAR  waiters hold NO cash (default): a CASH collection answers 403 cash_holding_not_allowed
 *   POOL_BAR                             waiter_cash_holding = true, waiter_cash_in_hand_limit = 100000 (handover flow)
 * Try it: wait1 (RESTAURANT) collects card/transfer; wait2 (POOL_BAR) collects cash; cashier2 / cashier1 / supervisor1 confirm. Idempotent.
 */
class CollectionDemoSeeder implements DemoSeeder
{
    public function priority(): int
    {
        return 130;
    }

    public function run(DemoContext $ctx): void
    {
        $rows = [];
        foreach (['RESTAURANT', 'INDOOR_CLUB', 'POOL_BAR', 'BUSH_BAR'] as $code) {
            $facility = DemoIds::facility($code);
            $f = DB::table('facility_unit')->where('id', Ids::toBinary($facility))->first();
            if ($f === null) {
                continue;
            }
            $id = DemoIds::of('payment-terminal:'.$code);
            DB::table('payment_terminal')->updateOrInsert(['id' => Ids::toBinary($id)], [
                'organization_id' => $f->organization_id, 'site_id' => $f->site_id, 'facility_unit_id' => $f->id,
                'provider' => 'MANUAL_BANK', 'label' => ucwords(strtolower(str_replace('_', ' ', $code))).' bank POS', 'serial' => 'DEMO-'.$code, 'status' => 'ACTIVE',
            ]);
            $rows[] = [$code, $id, 'MANUAL_BANK'];
        }
        $cap = DB::table('facility_capability')->where('facility_unit_id', Ids::toBinary(DemoIds::facility('POOL_BAR')))->where('capability_code', 'TABLE_SERVICE')->value('id');
        if ($cap !== null) {
            foreach (['waiter_cash_holding' => 'true', 'waiter_cash_in_hand_limit' => '100000'] as $key => $value) {
                DB::table('operating_rule')->updateOrInsert(['facility_capability_id' => $cap, 'rule_key' => $key], ['id' => Ids::toBinary(DemoIds::of("rule:POOL_BAR:{$key}")), 'rule_value' => $value]);
            }
        }
        $ctx->table('Demo payment terminals (waiter collection)', ['facility', 'terminal id', 'provider'], $rows);
        $ctx->info('  '.count($rows).' payment terminals; POOL_BAR waiters may hold cash (limit N100,000), other facilities may not');
    }
}
