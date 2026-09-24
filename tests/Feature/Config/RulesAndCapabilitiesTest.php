<?php

namespace Tests\Feature\Config;

use App\Domain\Booking\Models\BookableResource;
use App\Domain\Booking\Services\BookingRules;
use App\Domain\Config\Support\CapabilityCatalogue;
use App\Domain\Config\Support\RuleDefinitions;
use App\Domain\Inventory\Services\ConsumptionService;
use App\Domain\Orders\Services\OperatingRules;
use App\Support\Demo\DemoIds;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class RulesAndCapabilitiesTest extends ConfigTestCase
{
    /** A facility with every capability enabled, so every rule definition is applicable. */
    private function allCapsFacility(): string
    {
        return $this->owner()->post('/organization/facilities', ['code' => 'ALLCAPS', 'name' => 'All caps', 'capabilities' => array_keys(CapabilityCatalogue::all())])->assertStatus(201)->json('id');
    }

    public static function ruleKeys(): array
    {
        return array_map(fn ($k) => [$k], array_keys(RuleDefinitions::all()));
    }

    /** @return array{0: mixed, 1: mixed} a valid and an invalid API value for the definition */
    private function samples(array $d): array
    {
        return match ($d['type']) {
            'bool' => [! ($d['default'] ?? false), 'yes'],
            'enum' => [end($d['allowed'])['value'], 'NOPE'],
            'multi_enum' => [[$d['allowed'][0]['value']], ['bogus']],
            'number', 'duration' => [max(1, (int) ($d['min'] ?? 1)), ($d['max'] ?? 1000) + 1],
            'money' => ['1250.5', '-3'],
            'facility' => [DemoIds::facility('RECEPTION'), 'not-a-facility'],
        };
    }

    #[DataProvider('ruleKeys')]
    public function test_every_rule_definition_validates_and_round_trips(string $key): void
    {
        $d = RuleDefinitions::get($key);
        $fid = $this->allCapsFacility();
        $o = $this->owner();
        [$valid, $invalid] = $this->samples($d);
        $ver = fn () => '"'.DB::table('facility_unit')->where('id', Ids::toBinary($fid))->value('row_version').'"';

        $bad = $o->put("/facilities/{$fid}/operating-rules", ['rules' => [$key => $invalid], 'confirm' => true], ['If-Match' => $ver()])->assertStatus(422);
        $this->assertSame('validation_failed', $bad->json('code'));
        $this->assertArrayHasKey("rules.{$key}", $bad->json('errors'), 'field error is keyed by rule');

        $ok = $o->put("/facilities/{$fid}/operating-rules", ['rules' => [$key => $valid], 'confirm' => true], ['If-Match' => $ver()])->assertOk();
        $this->assertSame([$key], $ok->json('changed'));
        $expected = $d['type'] === 'money' ? '1250.5000' : $valid;
        $this->assertEquals($expected, $ok->json("values.{$key}"), "value of {$key}");
        $item = collect($ok->json('items'))->firstWhere('key', $key);
        $this->assertFalse($item['isDefault']);

        // GET agrees, then reset to default
        $this->assertEquals($expected, $o->get("/facilities/{$fid}/operating-rules")->json("values.{$key}"));
        $reset = $o->put("/facilities/{$fid}/operating-rules", ['rules' => [$key => null], 'confirm' => true], ['If-Match' => $ver()])->assertOk();
        $this->assertTrue(collect($reset->json('items'))->firstWhere('key', $key)['isDefault']);
        $this->assertEquals(RuleDefinitions::defaultOf($d), $reset->json("values.{$key}"));

        $a = $this->audit('config.rules.update', $fid);
        $this->assertGreaterThanOrEqual(2, count($a));
        $this->assertArrayHasKey($key, $a[0]->new);
    }

    public function test_unknown_rule_and_inapplicable_capability(): void
    {
        $o = $this->owner();
        $store = DemoIds::facility('MAIN_STORE');
        $v = fn () => '"'.DB::table('facility_unit')->where('id', Ids::toBinary($store))->value('row_version').'"';
        $r = $o->put("/facilities/{$store}/operating-rules", ['rules' => ['made_up' => 1, 'payment_timing' => 'PAY_FIRST']], ['If-Match' => $v()])->assertStatus(422);
        $this->assertArrayHasKey('rules.made_up', $r->json('errors'));
        $this->assertStringContainsString('Needs capability', $r->json('errors')['rules.payment_timing'][0]);
        $o->put("/facilities/{$store}/operating-rules", ['rules' => []], ['If-Match' => $v()])->assertStatus(422);
    }

    public function test_danger_rules_need_confirmation_and_payment_facility_must_accept_payments(): void
    {
        $o = $this->owner();
        $rest = DemoIds::facility('RESTAURANT');
        $v = fn () => '"'.DB::table('facility_unit')->where('id', Ids::toBinary($rest))->value('row_version').'"';
        $r = $o->put("/facilities/{$rest}/operating-rules", ['rules' => ['require_cash_session' => false]], ['If-Match' => $v()])->assertStatus(422);
        $this->assertSame('danger_confirmation_required', $r->json('code'));
        $o->put("/facilities/{$rest}/operating-rules", ['rules' => ['require_cash_session' => false], 'confirm' => true], ['If-Match' => $v()])->assertOk();
        // low/medium rules do not need confirm
        $o->put("/facilities/{$rest}/operating-rules", ['rules' => ['allow_offline_orders' => false]], ['If-Match' => $v()])->assertOk();
        // payment facility must accept payments
        $bad = $o->put("/facilities/{$rest}/operating-rules", ['rules' => ['payment_facility_unit_id' => DemoIds::facility('MAIN_STORE')]], ['If-Match' => $v()])->assertStatus(422);
        $this->assertArrayHasKey('rules.payment_facility_unit_id', $bad->json('errors'));
        $o->put("/facilities/{$rest}/operating-rules", ['rules' => ['payment_facility_unit_id' => DemoIds::facility('RECEPTION')]], ['If-Match' => $v()])->assertOk();
    }

    public function test_rule_changes_reach_the_runtime_and_are_versioned_and_outboxed(): void
    {
        $o = $this->owner();
        $rest = DemoIds::facility('RESTAURANT');
        $v0 = (int) DB::table('facility_unit')->where('id', Ids::toBinary($rest))->value('row_version');
        $o->put("/facilities/{$rest}/operating-rules", ['rules' => ['approval_threshold_amount' => '7500', 'payment_timing' => 'PAY_FIRST', 'stock_consumption_timing' => 'SETTLE'], 'confirm' => true], ['If-Match' => '"'.$v0.'"'])->assertOk()->assertHeader('ETag', '"'.($v0 + 1).'"');
        // the app-facing effective view sees it
        $eff = $o->get("/facilities/{$rest}/capabilities")->json();
        $this->assertSame('7500.0000', $eff['operatingRules']['approvalThresholdAmount']);
        $this->assertSame('PAY_FIRST', $eff['operatingRules']['paymentTiming']);
        $this->assertSame($v0 + 1, $eff['version']);
        // the Inventory module reads the same row
        $this->assertSame('SETTLE', app(ConsumptionService::class)->timingFor($rest));
        $this->assertSame('7500.0000', app(OperatingRules::class)->forFacility($rest)['approvalThresholdAmount']);

        $ev = $this->outbox($rest, 'facilityRules');
        $this->assertSame([$v0 + 1], array_column($ev, 'version'));
        $this->assertSame('7500.0000', $ev[0]['payload']['changes']['rules']['approval_threshold_amount']);
        $a = $this->audit('config.rules.update', $rest)[0];
        $this->assertSame('5000.0000', $a->old['approval_threshold_amount']);
        $this->assertSame('7500.0000', $a->new['approval_threshold_amount']);

        // stale + missing header
        $o->put("/facilities/{$rest}/operating-rules", ['rules' => ['allow_offline_orders' => false]], ['If-Match' => '"'.$v0.'"'])->assertStatus(412);
        $o->put("/facilities/{$rest}/operating-rules", ['rules' => ['allow_offline_orders' => false]])->assertStatus(428);
        // no-op does not bump
        $o->put("/facilities/{$rest}/operating-rules", ['rules' => ['approval_threshold_amount' => '7500.00']], ['If-Match' => '"'.($v0 + 1).'"'])->assertOk()->assertJsonPath('changed', [])->assertHeader('ETag', '"'.($v0 + 1).'"');
    }

    public function test_booking_rules_are_written_where_booking_reads_them_and_offline_strategy_writes_through(): void
    {
        $o = $this->owner();
        $arena = DemoIds::facility('FOOTBALL');
        $v = fn () => '"'.DB::table('facility_unit')->where('id', Ids::toBinary($arena))->value('row_version').'"';
        $o->put("/facilities/{$arena}/operating-rules", ['rules' => [
            'hold_ttl_seconds' => 420, 'cancel_cutoff_minutes' => 60, 'cancel_fee_percent' => 25.5,
            'booking_offline_strategy' => 'B_ONLINE_AUTHORITY_REQUIRED', 'booking_online_stale_after_seconds' => 300, 'booking_local_reserve_percent' => 50,
        ], 'confirm' => true], ['If-Match' => $v()])->assertOk();
        $rule = DB::table('booking_rule')->where('facility_unit_id', Ids::toBinary($arena))->first();
        $this->assertSame(420, (int) $rule->hold_ttl_seconds);
        $this->assertSame(60, (int) $rule->cancel_cutoff_minutes);
        $this->assertSame('25.50', $rule->cancel_fee_percent);
        $res = DB::table('bookable_resource')->where('facility_unit_id', Ids::toBinary($arena))->first();
        $this->assertSame('B_ONLINE_AUTHORITY_REQUIRED', $res->offline_strategy);
        $this->assertSame(300, (int) $res->online_stale_after_seconds);
        $this->assertSame(0, (int) $res->local_reserve_units, 'capacity 1 * 50% floored');
        // the Booking module resolves the same numbers
        $resource = BookableResource::query()->where('facility_unit_id', $arena)->first();
        $eff = app(BookingRules::class)->for($resource);
        $this->assertSame(420, (int) $eff['hold_ttl_seconds']);
        $this->assertSame(60, (int) $eff['cancel_cutoff_minutes']);
        $this->assertSame(420, $o->get("/facilities/{$arena}/capabilities")->json('operatingRules.holdTtlSeconds'), 'mirrored for the apps');
        $this->assertSame(420, $o->get("/facilities/{$arena}/operating-rules")->json('values.hold_ttl_seconds'));
    }

    public function test_capability_dependencies(): void
    {
        $o = $this->owner();
        $id = $o->post('/organization/facilities', ['code' => 'DEPS', 'name' => 'Deps'])->json('id');
        $put = fn (array $caps, string $ver) => $o->put("/facilities/{$id}/capabilities", ['capabilities' => $caps], ['If-Match' => '"'.$ver.'"']);

        $r = $put(['KITCHEN_ROUTING'], '1')->assertStatus(422);
        $this->assertSame('capability_dependency', $r->json('code'));
        $this->assertStringContainsString('POS', $r->json('errors')['capabilities.KITCHEN_ROUTING'][0]);
        $put(['NOPE_CAP'], '1')->assertStatus(422)->assertJsonPath('code', 'capability_dependency');
        $put(['POS', 'KITCHEN_ROUTING', 'OPEN_TAB'], '1')->assertOk()->assertHeader('ETag', '"2"');
        $eff = $o->get("/facilities/{$id}/capabilities")->json('capabilities');
        $this->assertEqualsCanonicalizing(['POS', 'KITCHEN_ROUTING', 'OPEN_TAB'], $eff);
        // cannot drop POS while KITCHEN_ROUTING remains
        $d = $put(['KITCHEN_ROUTING', 'OPEN_TAB'], '2')->assertStatus(422);
        $this->assertStringContainsString('turn KITCHEN_ROUTING off first', implode(' ', $d->json('errors')['capabilities.POS']));
        // dropping both together is fine; re-enabling brings rules back
        $o->put("/facilities/{$id}/operating-rules", ['rules' => ['allow_open_tabs' => false]], ['If-Match' => '"2"'])->assertOk();
        $put(['OPEN_TAB'], '3')->assertStatus(422); // OPEN_TAB requires POS
        $put([], '3')->assertOk();
        $this->assertSame([], $o->get("/facilities/{$id}/capabilities")->json('capabilities'));
        $put(['POS', 'OPEN_TAB'], '4')->assertOk();
        $this->assertFalse($o->get("/facilities/{$id}/operating-rules")->json('values.allow_open_tabs'), 'rule kept while the capability was off');
        $this->assertCount(3, $this->audit('config.capabilities.update', $id));
        $this->assertSame([2, 4, 5], array_column($this->outbox($id, 'facilityCapabilities'), 'version'));
        $put(['POS', 'OPEN_TAB'], '5')->assertOk()->assertJsonPath('changed', false)->assertHeader('ETag', '"5"');
        $put(['POS'], '3')->assertStatus(412);
    }

    public function test_disabling_a_capability_in_use_is_refused_with_reasons(): void
    {
        $o = $this->owner();
        $cafe = DemoIds::facility('CAFE');
        $v = fn () => '"'.DB::table('facility_unit')->where('id', Ids::toBinary($cafe))->value('row_version').'"';
        $caps = $o->get("/facilities/{$cafe}/capabilities")->json('capabilities');
        $without = fn (string $c) => array_values(array_diff($caps, [$c]));

        // open order -> cannot turn POS off
        $staff = DB::table('staff')->first(['id']);
        DB::table('order')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'organization_id' => Ids::toBinary(DemoIds::org()), 'site_id' => Ids::toBinary(DemoIds::site()),
            'facility_unit_id' => Ids::toBinary($cafe), 'order_number' => 'CFGCAP-'.random_int(100000, 999999), 'status' => 'READY', 'created_by' => $staff->id]);
        // POS is required by nothing else in CAFE except... nothing; PAYMENT_ACCEPTANCE has an open cash session
        DB::table('cash_session')->count();
        $r = $o->put("/facilities/{$cafe}/capabilities", ['capabilities' => $without('POS')], ['If-Match' => $v()])->assertStatus(409);
        $this->assertSame('capability_in_use', $r->json('code'));
        $this->assertSame('open_orders', $r->json('blockers.0.type'));
        $this->assertSame('POS', $r->json('blockers.0.capability'));
        $this->assertStringContainsString('open order', $r->json('detail'));
        $this->assertContains('POS', $o->get("/facilities/{$cafe}/capabilities")->json('capabilities'), 'nothing changed');

        DB::table('order')->where('facility_unit_id', Ids::toBinary($cafe))->update(['status' => 'VOIDED']);
        $o->put("/facilities/{$cafe}/capabilities", ['capabilities' => $without('POS')], ['If-Match' => $v()])->assertOk();
    }

    public function test_capability_and_rule_permissions_are_separate(): void
    {
        $noCaps = $this->managerLacking(['config.manage.capabilities']);
        $rest = DemoIds::facility('RESTAURANT');
        $noCaps->put("/facilities/{$rest}/capabilities", ['capabilities' => ['POS']], ['If-Match' => '"1"'])->assertStatus(403)->assertJsonPath('permission', 'config.manage.capabilities');
        $noCaps->put("/facilities/{$rest}/operating-rules", ['rules' => ['allow_offline_orders' => false]], ['If-Match' => '"1"'])->assertOk(); // has config.manage.rules
        $noRules = $this->managerLacking(['config.manage.rules']);
        $noRules->put("/facilities/{$rest}/operating-rules", ['rules' => ['allow_offline_orders' => false]], ['If-Match' => '"1"'])->assertStatus(403)->assertJsonPath('permission', 'config.manage.rules');
        $noRules->get("/facilities/{$rest}/operating-rules")->assertOk(); // config.view is enough to read
        $this->api('cashier1')->get("/facilities/{$rest}/operating-rules")->assertStatus(403);
        $this->api('cashier1')->get('/organization/rule-definitions')->assertStatus(403);
    }

    public function test_rule_definitions_catalogue_shape(): void
    {
        $items = $this->owner()->get('/organization/rule-definitions')->assertOk()->json('items');
        $this->assertGreaterThanOrEqual(30, count($items));
        foreach ($items as $i) {
            foreach (['key', 'label', 'description', 'group', 'type', 'capability', 'default', 'unit', 'allowed', 'dangerLevel', 'enforcement'] as $f) {
                $this->assertArrayHasKey($f, $i, "{$i['key']}.{$f}");
            }
            $this->assertContains($i['type'], ['enum', 'multi_enum', 'number', 'bool', 'duration', 'money', 'facility']);
            $this->assertContains($i['dangerLevel'], ['low', 'medium', 'high']);
            $this->assertNotSame('', trim($i['description']));
        }
        $types = $this->owner()->get('/organization/capability-types')->assertOk()->json('items');
        $this->assertCount(count(CapabilityCatalogue::all()), $types);
        $this->assertSame(['POS'], collect($types)->firstWhere('code', 'KITCHEN_ROUTING')['requires']);
    }
}
