<?php

namespace Tests\Feature\Orders;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestData;

class CatalogTest extends OrdersTestCase
{
    public function test_products_resolve_price_tax_and_prep_route_for_a_facility(): void
    {
        $this->f->setVat(true, '7.5', true);
        $r = $this->api('waiter', 'GET', "/catalog/products?facilityId={$this->f->restaurant->id}")->assertOk();
        $r->assertHeader('ETag');
        $items = collect($r->json('items'))->keyBy('sku');
        $this->assertCount(4, $items);
        $j = $items['JOLLOF'];
        $this->assertSame('4500.0000', $j['price']);
        $this->assertSame('NGN', $j['currency']);
        $this->assertSame('FOOD', $j['kind']);
        $this->assertSame('7.5', $j['taxRatePercent']);
        $this->assertSame('313.9535', $j['taxAmount']);
        $this->assertTrue($j['taxInclusive']);
        $this->assertSame('KITCHEN', $j['prepRoute']['kind']);
        $this->assertSame($this->f->kitchenStation, $j['prepRoute']['stationId']);
        $this->assertSame('Kitchen Pass', $j['prepRoute']['stationName']);
        $this->assertSame('DRINK', $items['CHAPMAN']['kind']);
        $this->assertSame('BAR', $items['CHAPMAN']['prepRoute']['kind']);
        $this->assertSame('RETAIL', $items['WATER']['kind']);
        $this->assertSame('NONE', $items['WATER']['prepRoute']['kind']);
        $this->assertTrue($j['active']);
        $this->assertNull($r->json('nextCursor'));
    }

    public function test_vat_off_reports_zero_tax(): void
    {
        $j = collect($this->api('waiter', 'GET', "/catalog/products?facilityId={$this->f->restaurant->id}")->json('items'))->firstWhere('sku', 'JOLLOF');
        $this->assertSame('0', $j['taxRatePercent']);
        $this->assertSame('0.0000', $j['taxAmount']);
    }

    public function test_facility_price_override_beats_the_list_price_and_products_are_per_facility(): void
    {
        $r = $this->api('manager', 'PUT', "/catalog/products/{$this->f->products['jollof']}/price", ['amount' => '5000', 'facilityId' => $this->f->restaurant->id])->assertOk();
        $j = collect($this->api('waiter', 'GET', "/catalog/products?facilityId={$this->f->restaurant->id}")->json('items'))->firstWhere('sku', 'JOLLOF');
        $this->assertSame('5000.0000', $j['price']);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'catalog.price.set')->count());
        $this->assertSame('5000.0000', $this->draft(['jollof' => 1])->json('total'));
        // the club sells nothing yet
        $this->assertSame([], $this->api('manager', 'GET', "/catalog/products?facilityId={$this->f->club->id}")->json('items'));
        $this->assertNotNull($r->json('rowVersion'));
    }

    public function test_filters_search_pagination_and_conditional_get(): void
    {
        $base = "/catalog/products?facilityId={$this->f->restaurant->id}";
        $this->assertSame(['CHAPMAN'], collect($this->api('waiter', 'GET', $base.'&filter[kind]=DRINK')->json('items'))->pluck('sku')->all());
        $this->assertSame(['JOLLOF'], collect($this->api('waiter', 'GET', $base.'&q=jol')->json('items'))->pluck('sku')->all());
        $p1 = $this->api('waiter', 'GET', $base.'&limit=3')->assertOk();
        $this->assertCount(3, $p1->json('items'));
        $this->assertNotNull($p1->json('nextCursor'));
        $p2 = $this->api('waiter', 'GET', $base.'&limit=3&cursor='.$p1->json('nextCursor'))->assertOk();
        $this->assertCount(1, $p2->json('items'));
        $this->assertNull($p2->json('nextCursor'));
        $etag = $this->etag($this->api('waiter', 'GET', $base));
        $this->api('waiter', 'GET', $base, [], ['If-None-Match' => $etag])->assertStatus(304);
        $this->assertSame([], $this->api('waiter', 'GET', $base.'&updatedSince=2999-01-01T00:00:00Z')->json('items'));
        $this->api('waiter', 'GET', '/catalog/products')->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    public function test_eighty_sixing_an_item_makes_it_unavailable_and_unsellable(): void
    {
        $pid = $this->f->products['suya'];
        $fid = $this->f->restaurant->id;
        $this->api('supervisor', 'PUT', "/catalog/products/{$pid}/availability/{$fid}", ['available' => false, 'reason' => 'sold out'])->assertOk()
            ->assertJsonPath('available', false)->assertJsonPath('reason', 'MANUALLY_DISABLED');
        $av = $this->api('waiter', 'GET', "/catalog/availability?facilityId={$fid}&filter[productId]={$pid}")->assertOk();
        $this->assertFalse($av->json('items.0.available'));
        $suya = collect($this->api('waiter', 'GET', "/catalog/products?facilityId={$fid}")->json('items'))->firstWhere('sku', 'SUYA');
        $this->assertFalse($suya['active']);
        $d = $this->draft(['jollof' => 1]);
        $this->api('waiter', 'POST', '/orders/'.$d->json('id').'/lines', ['productId' => $pid, 'quantity' => 1], ['If-Match' => $this->etag($d)])
            ->assertStatus(409)->assertJsonPath('code', 'product_unavailable');
        $this->api('supervisor', 'PUT', "/catalog/products/{$pid}/availability/{$fid}", ['available' => true])->assertOk()->assertJsonPath('available', true);
        $this->api('waiter', 'POST', '/orders/'.$d->json('id').'/lines', ['productId' => $pid, 'quantity' => 1], ['If-Match' => $this->etag($d)])->assertStatus(201);
        $this->assertSame(2, DB::table('audit_log')->where('action', 'catalog.availability.set')->count());
    }

    public function test_product_not_sold_at_the_facility_is_capability_disabled(): void
    {
        $waiter = TestData::staff($this->f->t, 'clubwaiter');
        TestData::assign($waiter, 'WAIT_STAFF', 'FACILITY_UNIT', $this->f->club->id);
        $this->api('clubwaiter', 'POST', '/orders', ['facilityId' => $this->f->club->id, 'lines' => [['productId' => $this->f->products['jollof'], 'quantity' => 1]]])
            ->assertStatus(422)->assertJsonPath('code', 'capability_disabled');
        $this->assertSame(0, DB::table('order')->count());
        $av = $this->api('supervisor', 'GET', "/catalog/availability?facilityId={$this->f->club->id}&filter[productId]={$this->f->products['jollof']}");
        $this->assertSame('NOT_SOLD_HERE', $av->json('items.0.reason'));
    }

    public function test_admin_catalog_crud_is_permissioned_and_audited(): void
    {
        $cat = $this->api('manager', 'POST', '/catalog/categories', ['name' => 'Desserts', 'sortOrder' => 5])->assertStatus(201);
        $this->api('waiter', 'POST', '/catalog/categories', ['name' => 'Nope'])->assertStatus(403)->assertJsonPath('permission', 'catalog.manage');
        $this->api('fakemanager', 'POST', '/catalog/categories', ['name' => 'Nope'])->assertStatus(403);
        $p = $this->api('manager', 'POST', '/catalog/products', [
            'sku' => 'CAKE1', 'name' => 'Chocolate Cake', 'categoryId' => $cat->json('id'), 'kind' => 'GOOD', 'prepRouteId' => $this->f->kitchenRoute,
            'facilityIds' => [$this->f->restaurant->id], 'price' => '3500.50',
        ])->assertStatus(201);
        $this->assertSame('FOOD', $p->json('kind'));
        $this->assertSame([['facilityId' => null, 'amount' => '3500.5000']], $p->json('prices'));
        $this->api('manager', 'POST', '/catalog/products', ['sku' => 'CAKE1', 'name' => 'Dup', 'categoryId' => $cat->json('id')])->assertStatus(409)->assertJsonPath('code', 'sku_taken');
        $listed = collect($this->api('waiter', 'GET', "/catalog/products?facilityId={$this->f->restaurant->id}")->json('items'))->firstWhere('sku', 'CAKE1');
        $this->assertSame('3500.5000', $listed['price']);

        $u = $this->api('manager', 'PATCH', '/catalog/products/'.$p->json('id'), ['name' => 'Choc Cake', 'active' => false], ['If-Match' => $this->etag($p)])->assertOk();
        $this->assertFalse($u->json('active'));
        $this->api('manager', 'PATCH', '/catalog/products/'.$p->json('id'), ['name' => 'X'], ['If-Match' => $this->etag($p)])->assertStatus(412);
        $this->assertNull(collect($this->api('waiter', 'GET', "/catalog/products?facilityId={$this->f->restaurant->id}")->json('items'))->firstWhere('sku', 'CAKE1'));
        $this->assertSame(['catalog.category.create', 'catalog.product.create', 'catalog.product.update'],
            DB::table('audit_log')->where('action', 'like', 'catalog.%')->orderBy('seq')->pluck('action')->all());
    }

    public function test_vat_setting_changed_through_the_admin_endpoint_drives_order_pricing(): void
    {
        // ADR-0011: the setting lives in the Organization module; pricing must honour it live (default OFF)
        $owner = TestData::staff($this->f->t, 'owner');
        TestData::assign($owner, 'OWNER', 'ORGANIZATION');
        $this->assertSame('9000.0000', $this->draft(['jollof' => 2])->json('total'));
        $g = $this->api('owner', 'GET', '/admin/settings/tax')->assertOk();
        $this->assertFalse($g->json('vatEnabled'));
        $this->api('owner', 'PUT', '/admin/settings/tax', ['vatEnabled' => true, 'vatRatePercent' => '7.5', 'pricesTaxInclusive' => false, 'vatNumber' => 'TIN-123'], ['If-Match' => $this->etag($g)])->assertOk();
        $r = $this->draft(['jollof' => 2], 'waiter', 'T2');
        $this->assertSame('9675.0000', $r->json('total')); // exclusive 7.5 %
        $this->assertSame('675.0000', $r->json('taxTotal'));
    }

    public function test_categories_list(): void
    {
        $cat = DB::table('product_category')->first();
        $r = $this->api('waiter', 'GET', '/catalog/categories')->assertOk();
        $this->assertSame('Menu', $r->json('items.0.name'));
        $this->assertSame(Ids::fromBinary($cat->id), $r->json('items.0.id'));
    }
}
