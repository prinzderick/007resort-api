<?php

namespace Tests\Feature\Config;

use App\Domain\Sync\Services\OutboxPublisher;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestData;
use Tests\TwoNodeTestCase;

/**
 * End to end: an admin configures the property on the LOCAL node through the API; the outbox drains to the CLOUD node (real HTTP kernel between two
 * real MySQL databases) and the cloud ends up with the same configuration, applied version-checked. Stale edits raise a conflict instead of overwriting.
 */
class ConfigSyncTest extends TwoNodeTestCase
{
    private array $auth = [];

    private function api(string $method, string $uri, array $body = [], array $headers = [])
    {
        $h = ['Authorization' => 'Bearer '.$this->auth['accessToken'], 'Idempotency-Key' => 'k-'.bin2hex(random_bytes(8))] + $headers;

        return $this->withHeaders($h)->json($method, '/api/v1'.$uri, $body);
    }

    private function publish(): array
    {
        $r = $this->onNode('local', fn () => app(OutboxPublisher::class)->run());
        $left = $this->onNode('local', fn () => DB::table('outbox_event')->where('sync_status', '!=', 'SYNCED')->get(['event_type', 'entity_type', 'entity_version', 'sync_status', 'last_error', 'payload'])->map(fn ($e) => [$e->entity_type, (int) $e->entity_version, $e->sync_status, substr((string) $e->last_error, 0, 400), substr((string) $e->payload, 0, 150)])->all());
        $this->assertSame([], $left, 'everything drained: '.json_encode($r));

        return $r;
    }

    private function both(callable $q): array
    {
        return [$this->onNode('local', $q), $this->onNode('cloud', $q)];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->onNode('local', function () {
            $t = ['org' => $this->org, 'site' => $this->site];
            $staff = TestData::staff($t, 'syncadmin', TestData::PASSWORD, '1234');
            TestData::assign($staff, 'OWNER', 'ORGANIZATION');
        });
        $this->useNode('local');
        $this->auth = $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'PIN', 'identifier' => 'syncadmin', 'secret' => '1234'])->assertOk()->json();
    }

    public function test_facility_configuration_reaches_the_cloud_and_stale_edits_conflict(): void
    {
        $f = $this->api('POST', '/organization/facilities', ['code' => 'SYNC_REST', 'name' => 'Sync Restaurant', 'templateKey' => 'RESTAURANT', 'description' => 'first', 'timezone' => 'Africa/Lagos',
            'openingHours' => ['weekly' => ['mon' => [['open' => '08:00', 'close' => '22:00']]]], 'contact' => ['phone' => '+234']])->assertStatus(201);
        $fid = $f->json('id');
        $v = fn () => '"'.DB::table('facility_unit')->where('id', Ids::toBinary($fid))->value('row_version').'"';
        $this->api('PATCH', "/organization/facilities/{$fid}", ['name' => 'Sync Restaurant 2', 'sortOrder' => 4], ['If-Match' => $v()])->assertOk();
        $this->api('PUT', "/facilities/{$fid}/capabilities", ['capabilities' => array_merge($f->json('capabilities'), ['TICKETING'])], ['If-Match' => $v()])->assertOk();
        $this->api('PUT', "/facilities/{$fid}/operating-rules", ['rules' => ['approval_threshold_amount' => '7500', 'payment_timing' => 'PAY_FIRST'], 'confirm' => true], ['If-Match' => $v()])->assertOk();
        $this->api('PUT', "/facilities/{$fid}/payment-methods", ['methods' => ['CARD' => false]], ['If-Match' => $v()])->assertOk();
        $op = $this->api('POST', "/organization/facilities/{$fid}/operating-points", ['code' => 'PASS2', 'name' => 'Second pass', 'kind' => 'STATION', 'kdsStation' => ['kind' => 'KITCHEN']])->assertStatus(201);
        $this->api('POST', "/organization/facilities/{$fid}/tables/bulk", ['prefix' => 'T', 'from' => 1, 'to' => 4, 'seats' => 4])->assertStatus(201);
        $this->api('POST', "/organization/facilities/{$fid}/deactivate", [], ['If-Match' => $v()])->assertOk();
        $this->api('POST', "/organization/facilities/{$fid}/reactivate", [], ['If-Match' => $v()])->assertOk();

        $this->publish();

        [$l, $c] = $this->both(fn () => DB::table('facility_unit')->where('id', Ids::toBinary($fid))->first());
        $this->assertNotNull($c, 'facility exists on the cloud');
        foreach (['code', 'name', 'kind', 'description', 'timezone', 'sort_order', 'template_key', 'is_active', 'row_version'] as $col) {
            $this->assertEquals($l->{$col}, $c->{$col}, "facility.{$col}");
        }
        $this->assertEquals(json_decode($l->opening_hours, true), json_decode($c->opening_hours, true));
        $this->assertSame('Sync Restaurant 2', $c->name);
        [$lc, $cc] = $this->both(fn () => DB::table('facility_capability')->where('facility_unit_id', Ids::toBinary($fid))->where('is_enabled', 1)->orderBy('capability_code')->pluck('capability_code')->all());
        $this->assertSame($lc, $cc);
        $this->assertContains('TICKETING', $cc);
        [$lr, $cr] = $this->both(fn () => DB::table('operating_rule as r')->join('facility_capability as x', 'x.id', '=', 'r.facility_capability_id')->where('x.facility_unit_id', Ids::toBinary($fid))->orderBy('r.rule_key')->pluck('r.rule_value', 'r.rule_key')->all());
        $this->assertSame($lr, $cr);
        $this->assertSame('7500.0000', $cr['approval_threshold_amount']);
        [$lp, $cp] = $this->both(fn () => DB::table('facility_payment_method')->where('facility_unit_id', Ids::toBinary($fid))->orderBy('method')->pluck('is_enabled', 'method')->all());
        $this->assertSame($lp, $cp);
        $this->assertEquals(0, $cp['CARD']);
        [$lo, $co] = $this->both(fn () => DB::table('operating_point')->where('facility_unit_id', Ids::toBinary($fid))->orderBy('code')->pluck('name', 'code')->all());
        $this->assertSame($lo, $co);
        $this->assertNotNull($this->onNode('cloud', fn () => DB::table('kds_station')->where('id', Ids::toBinary($op->json('id')))->first()), 'KDS station created on the cloud');
        [$lt, $ct] = $this->both(fn () => DB::table('dining_table')->where('facility_unit_id', Ids::toBinary($fid))->orderBy('label')->pluck('seats', 'label')->all());
        $this->assertSame($lt, $ct);
        $this->assertCount(4, $ct);

        // a stale change from the other node is NOT applied; a conflict is recorded (never last-writer-wins)
        $this->onNode('cloud', fn () => DB::table('facility_unit')->where('id', Ids::toBinary($fid))->update(['name' => 'Cloud edit', 'row_version' => (int) $c->row_version + 1]));
        $this->api('PATCH', "/organization/facilities/{$fid}", ['description' => 'edited on local'], ['If-Match' => $v()])->assertOk();
        $this->onNode('local', fn () => app(OutboxPublisher::class)->run());
        $this->assertSame('CONFLICT', $this->onNode('local', fn () => DB::table('outbox_event')->orderByDesc('seq')->value('sync_status')));
        $this->assertSame('Cloud edit', $this->onNode('cloud', fn () => DB::table('facility_unit')->where('id', Ids::toBinary($fid))->value('name')));
        $this->assertNotSame('edited on local', $this->onNode('cloud', fn () => DB::table('facility_unit')->where('id', Ids::toBinary($fid))->value('description')));
        $this->assertSame(1, $this->onNode('cloud', fn () => DB::table('sync_conflict')->where('category', 'CONFIGURATION')->count()));
    }

    public function test_catalogue_tickets_bookings_settings_and_roles_reach_the_cloud(): void
    {
        $rest = $this->api('POST', '/organization/facilities', ['code' => 'SYNC_POOL', 'name' => 'Sync Pool', 'templateKey' => 'SPORTS_RESOURCE_GROUP'])->assertStatus(201)->json('id');
        $tk = $this->api('POST', '/organization/facilities', ['code' => 'SYNC_TKT', 'name' => 'Sync Tickets', 'capabilities' => ['TICKETING', 'TICKET_VALIDATION']])->assertStatus(201)->json('id');
        $cat = $this->api('POST', '/catalog/categories', ['name' => 'Sync mains'])->assertStatus(201)->json('id');
        $tax = $this->api('POST', '/catalog/tax-rates', ['code' => 'SYNCVAT', 'name' => 'Sync VAT', 'ratePercent' => '7.5'])->assertStatus(201)->json('id');
        $route = $this->api('POST', '/catalog/prep-routes', ['code' => 'SYNCROUTE', 'name' => 'Sync route', 'kind' => 'KITCHEN'])->assertStatus(201)->json('id');
        $p = $this->api('POST', '/catalog/products', ['sku' => 'SYNC-1', 'name' => 'Sync burger', 'categoryId' => $cat, 'taxRateId' => $tax, 'prepRouteId' => $route, 'barcode' => 'SYNC-BC', 'price' => '2500',
            'description' => 'Tasty', 'modifiers' => [['name' => 'Size', 'options' => [['name' => 'L', 'priceDelta' => '200']]]]])->assertStatus(201)->json();
        $this->api('PATCH', '/catalog/products/'.$p['id'], ['name' => 'Sync burger XL'], ['If-Match' => '"'.$p['rowVersion'].'"'])->assertOk();
        $this->api('PUT', "/catalog/products/{$p['id']}/facilities/{$rest}", ['available' => true, 'price' => '2600', 'sortOrder' => 3])->assertOk();
        $pl = $this->api('POST', '/catalog/price-lists', ['name' => 'Sync list'])->assertStatus(201)->json('id');
        $this->api('POST', '/catalog/prices', ['productId' => $p['id'], 'priceListId' => $pl, 'amount' => '2100', 'validFrom' => now('UTC')->addDays(5)->format('Y-m-d\TH:i:s\Z')])->assertStatus(201);
        $item = DB::table('inventory_item')->value('id');
        if ($item !== null) {
            $this->onNode('cloud', fn () => null);
        }
        $tt = $this->api('POST', '/ticketing/ticket-types', ['code' => 'SYNC-DAY', 'name' => 'Sync day pass', 'facilityId' => $tk, 'validationMode' => 'MULTIPLE_ENTRY', 'price' => '1500'])->assertStatus(201)->json('id');
        $res = $this->api('POST', '/bookings/resources', ['facilityId' => $rest, 'code' => 'CT1', 'name' => 'Court 1', 'mode' => 'TIME_SLOT', 'capacity' => 1])->assertStatus(201);
        $rid = $res->json('id');
        $rv = fn () => '"'.DB::table('bookable_resource')->where('id', Ids::toBinary($rid))->value('row_version').'"';
        $this->api('PUT', "/bookings/resources/{$rid}/schedule", ['windows' => [['dayOfWeek' => 1, 'open' => '08:00', 'close' => '20:00']]], ['If-Match' => $rv()])->assertOk();
        $this->api('PUT', "/bookings/resources/{$rid}/rules", ['holdTtlSeconds' => 200, 'cancelFeePercent' => 10], ['If-Match' => $rv()])->assertOk();
        $bo = $this->api('POST', '/bookings/blackouts', ['facilityId' => $rest, 'start' => now('UTC')->addDays(2)->format('Y-m-d\TH:i:s\Z'), 'end' => now('UTC')->addDays(3)->format('Y-m-d\TH:i:s\Z'), 'reason' => 'x'])->assertStatus(201)->json('id');
        $gone = $this->api('POST', '/bookings/blackouts', ['facilityId' => $rest, 'start' => now('UTC')->addDays(6)->format('Y-m-d\TH:i:s\Z'), 'end' => now('UTC')->addDays(7)->format('Y-m-d\TH:i:s\Z')])->assertStatus(201)->json('id');
        $this->api('DELETE', "/bookings/blackouts/{$gone}")->assertNoContent();
        $this->api('PUT', '/admin/settings/receipt', ['businessName' => 'Sync Resort', 'footer' => 'bye'], ['If-Match' => '"0"'])->assertOk();
        $bv = $this->api('GET', '/admin/settings/business')->json('rowVersion');
        $this->api('PUT', '/admin/settings/business', ['siteName' => 'Sync Otueke', 'organizationName' => 'Sync Org Ltd', 'address' => '1 Sync Rd'], ['If-Match' => '"'.$bv.'"'])->assertOk();
        $role = $this->api('POST', '/roles', ['name' => 'Sync clerk', 'permissions' => ['payment.take']])->assertStatus(201)->json('role');
        $this->api('PUT', "/roles/{$role['id']}/permissions", ['permissions' => [['code' => 'payment.take'], ['code' => 'receipt.view']]], ['If-Match' => '"'.$role['rowVersion'].'"'])->assertOk();
        $this->api('PUT', '/catalog/prep-route-stations', ['facilityId' => $rest, 'prepRouteId' => $route, 'kdsStationId' => DB::table('kds_station')->exists() ? Ids::fromBinary(DB::table('kds_station')->value('id')) : null])->assertOk();

        $this->publish();

        $same = function (string $label, callable $q) {
            [$l, $c] = $this->both($q);
            $this->assertEquals($l, $c, $label);
            $this->assertNotEmpty($c, "{$label} must exist on the cloud");
        };
        $pid = Ids::toBinary($p['id']);
        $same('category', fn () => DB::table('product_category')->where('name', 'Sync mains')->get(['name', 'sort_order', 'is_active'])->all());
        $same('tax', fn () => DB::table('tax_rate')->where('code', 'SYNCVAT')->get(['name', 'rate_percent', 'is_active', 'row_version'])->all());
        $same('route', fn () => DB::table('prep_route')->where('code', 'SYNCROUTE')->get(['name', 'kind'])->all());
        $same('product', fn () => DB::table('product')->where('id', $pid)->get(['sku', 'name', 'description', 'barcode', 'modifiers', 'kind', 'row_version'])->map(fn ($r) => (array) $r + ['modifiers' => json_decode($r->modifiers, true)])->all());
        $same('product at facility', fn () => DB::table('product_facility')->where('product_id', $pid)->get(['is_available', 'sort_order'])->all());
        $same('prices', fn () => DB::table('price')->where('product_id', $pid)->orderBy('amount')->get(['amount', 'is_active', 'facility_unit_id'])->all());
        $same('price list', fn () => DB::table('price_list')->where('name', 'Sync list')->get(['name', 'is_default'])->all());
        $same('ticket type', fn () => DB::table('ticket_type')->where('code', 'SYNC-DAY')->get(['name', 'validation_mode', 'validity_kind', 'is_active'])->all());
        $same('ticket product', fn () => DB::table('product')->where('sku', 'TKT-SYNC-DAY')->get(['name', 'kind'])->all());
        $same('schedule', fn () => DB::table('availability_schedule')->where('resource_id', Ids::toBinary($rid))->get(['day_of_week', 'open_time', 'close_time'])->all());
        $same('resource rules', fn () => DB::table('booking_rule')->where('resource_id', Ids::toBinary($rid))->get(['hold_ttl_seconds', 'cancel_fee_percent'])->all());
        $same('blackouts', fn () => DB::table('blackout')->where('facility_unit_id', Ids::toBinary($rest))->get(['reason'])->all());
        $this->assertNotNull($this->onNode('cloud', fn () => DB::table('blackout')->where('id', Ids::toBinary($bo))->first()));
        $this->assertNull($this->onNode('cloud', fn () => DB::table('blackout')->where('id', Ids::toBinary($gone))->first()), 'deleted blackout is removed on the cloud too');
        $same('receipt setting', fn () => DB::table('receipt_setting')->get(['business_name', 'footer', 'row_version'])->all());
        $same('business profile', fn () => DB::table('site')->get(['name', 'address'])->all());
        $this->assertSame('Sync Org Ltd', $this->onNode('cloud', fn () => DB::table('organization')->value('name')));
        $same('role', fn () => DB::table('role as r')->join('role_permission as rp', 'rp.role_id', '=', 'r.id')->join('permission as p', 'p.id', '=', 'rp.permission_id')->where('r.code', 'CUSTOM_SYNC_CLERK')->orderBy('p.code')->pluck('p.code')->all());
        $this->assertSame(0, $this->onNode('cloud', fn () => DB::table('outbox_event')->where('sync_status', 'FAILED')->count()));
        $this->assertSame(0, $this->onNode('cloud', fn () => DB::table('inbox_event')->where('result', 'FAILED')->count()));
        $this->assertNotNull($tt);
    }
}
