<?php

namespace Tests\Feature\Config;

use App\Domain\Catalog\Services\Pricing;
use App\Support\Demo\DemoIds;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

class CatalogConfigTest extends ConfigTestCase
{
    private function sku(string $sku): ?object
    {
        return DB::table('product')->where('sku', $sku)->first();
    }

    private function idem(array $h = []): array
    {
        return $h + ['Idempotency-Key' => 'k-'.bin2hex(random_bytes(8))];
    }

    private function newProduct(array $extra = []): array
    {
        $cat = $this->owner()->get('/catalog/categories')->json('items.0.id');

        return $this->owner()->post('/catalog/products', ['sku' => 'CFG-'.bin2hex(random_bytes(3)), 'name' => 'Config product', 'categoryId' => $cat, 'price' => '1000'] + $extra)->assertStatus(201)->json();
    }

    public function test_product_description_barcode_modifiers_and_barcode_uniqueness(): void
    {
        $o = $this->owner();
        $mods = [['name' => 'Doneness', 'required' => true, 'min' => 1, 'max' => 1, 'options' => [['name' => 'Rare'], ['name' => 'Well done', 'priceDelta' => '0.0000']]],
            ['name' => 'Extras', 'options' => [['name' => 'Cheese', 'priceDelta' => '300.0000']]]];
        $p = $this->newProduct(['description' => 'Grilled steak', 'barcode' => 'BC-100', 'modifiers' => $mods]);
        $this->assertSame('Grilled steak', $p['description']);
        $this->assertSame('BC-100', $p['barcode']);
        $this->assertEquals($this->ksortR($mods), $this->ksortR($p['modifiers']));
        $o->post('/catalog/products', ['sku' => 'CFG-DUP', 'name' => 'x', 'categoryId' => $p['categoryId'], 'barcode' => 'BC-100'])->assertStatus(409)->assertJsonPath('code', 'barcode_taken');
        $o->patch('/catalog/products/'.$p['id'], ['modifiers' => [['name' => 'Bad', 'options' => []]]], $this->idem())->assertStatus(422)->assertJsonValidationErrors(['modifiers']);
        $o->patch('/catalog/products/'.$p['id'], ['modifiers' => [['name' => 'A', 'options' => [['name' => 'x'], ['name' => 'X']]]]], $this->idem())->assertStatus(422);
        $u = $o->patch('/catalog/products/'.$p['id'], ['description' => 'Now with sauce', 'modifiers' => null], $this->idem(['If-Match' => '"'.$p['rowVersion'].'"']))->assertOk();
        $this->assertSame('Now with sauce', $u->json('description'));
        $this->assertNull($u->json('modifiers'));
        $o->patch('/catalog/products/'.$p['id'], ['name' => 'Stale'], $this->idem(['If-Match' => '"'.$p['rowVersion'].'"']))->assertStatus(412);
        $ev = $this->outbox($p['id'], 'product');
        $this->assertSame([1, 2], array_column($ev, 'version'));
        $this->assertSame('Now with sauce', $ev[1]['payload']['changes']['description']);
    }

    public function test_sell_at_facility_station_override_and_price_override(): void
    {
        $o = $this->owner();
        $p = $this->newProduct();
        $fac = DemoIds::facility('CAFE');
        $r = $o->put("/catalog/products/{$p['id']}/facilities/{$fac}", ['available' => true, 'price' => '1500', 'sortOrder' => 5], [])->assertOk();
        $row = collect($r->json('facilities'))->firstWhere('facilityId', $fac);
        $this->assertTrue($row['available']);
        $this->assertSame('1500.0000', $row['priceOverride']);
        $this->assertSame(5, $row['sortOrder']);
        // the runtime catalogue at that facility resolves the override
        $cafeItems = collect($o->get("/catalog/products?facilityId={$fac}")->json('items'))->firstWhere('id', $p['id']);
        $this->assertSame('1500.0000', $cafeItems['price']);
        // 86 via the existing endpoint still works, reason validated here
        $o->put("/catalog/products/{$p['id']}/facilities/{$fac}", ['available' => false, 'unavailableReason' => 'BOGUS'])->assertStatus(422);
        $o->put("/catalog/products/{$p['id']}/facilities/{$fac}", ['available' => false, 'unavailableReason' => 'OUT_OF_STOCK', 'price' => null])->assertOk();
        $this->assertNull(collect($o->get("/admin/catalog/products/{$p['id']}")->json('facilities'))->firstWhere('facilityId', $fac)['priceOverride']);
        // station override must be an active station
        $o->put("/catalog/products/{$p['id']}/facilities/{$fac}", ['available' => true, 'kdsStationId' => Ids::uuid7()])->assertStatus(422)->assertJsonValidationErrors(['kdsStationId']);
        $o->put("/catalog/products/{$p['id']}/facilities/{$fac}", ['available' => true, 'kdsStationId' => DemoIds::operatingPoint('MAIN_KITCHEN', 'MAIN_KITCHEN')])->assertOk();
        $o->delete("/catalog/products/{$p['id']}/facilities/{$fac}", idem: false)->assertOk();
        $this->assertNotEmpty($this->audit('config.catalog.product_facility.remove', $p['id']));
        $this->assertNotEmpty(DB::table('outbox_event')->where('entity_type', 'ProductFacility')->get());
    }

    public function test_price_lists_effective_dated_prices_and_overlaps(): void
    {
        $o = $this->owner();
        $p = $this->newProduct(['price' => '1000']);
        $lists = $o->get('/catalog/price-lists')->assertOk()->json('items');
        $default = collect($lists)->firstWhere('isDefault', true);
        $this->assertNotNull($default);

        $future = now('UTC')->addDays(10)->format('Y-m-d\TH:i:s\Z');
        $created = $o->post('/catalog/prices', ['productId' => $p['id'], 'amount' => '1200', 'validFrom' => $future])->assertStatus(201);
        $this->assertSame('1200.0000', $created->json('amount'));
        // the earlier open-ended price was end-dated at the new start
        $rows = $o->get("/catalog/prices?productId={$p['id']}")->assertOk()->json('items');
        $this->assertCount(2, $rows);
        $old = collect($rows)->firstWhere('amount', '1000.0000');
        $this->assertNotNull($old['validTo']);
        // today's runtime price is still the old one
        $this->assertSame('1000.0000', app(Pricing::class)->unitPrice($p['id'], DemoIds::facility('CAFE')));

        // a future price can be edited; an effective one cannot be re-priced, only end-dated
        $o->patch("/catalog/prices/{$created->json('id')}", ['amount' => '1250'], ['If-Match' => '"1"'])->assertOk()->assertJsonPath('amount', '1250.0000');
        $o->patch("/catalog/prices/{$old['id']}", ['amount' => '900'], ['If-Match' => '"'.$old['rowVersion'].'"'])->assertStatus(409)->assertJsonPath('code', 'price_immutable');
        // overlap: a bounded window inside the future price
        $mid = now('UTC')->addDays(12)->format('Y-m-d\TH:i:s\Z');
        $o->post('/catalog/prices', ['productId' => $p['id'], 'amount' => '999', 'validFrom' => now('UTC')->addDays(5)->format('Y-m-d\TH:i:s\Z'), 'validTo' => $mid])->assertStatus(409)->assertJsonPath('code', 'price_overlap');
        $o->post('/catalog/prices', ['productId' => $p['id'], 'amount' => '999', 'validFrom' => $mid, 'validTo' => $future])->assertStatus(422);
        // facility scoped price coexists with list-wide
        $fp = $o->post('/catalog/prices', ['productId' => $p['id'], 'facilityId' => DemoIds::facility('CAFE'), 'amount' => '1100'])->assertStatus(201);
        $this->assertSame('1100.0000', app(Pricing::class)->unitPrice($p['id'], DemoIds::facility('CAFE')));
        $o->patch("/catalog/prices/{$fp->json('id')}", ['active' => false], ['If-Match' => '"1"'])->assertOk();
        $this->assertSame('1000.0000', app(Pricing::class)->unitPrice($p['id'], DemoIds::facility('CAFE')));

        // price lists: exactly one default
        $l = $o->post('/catalog/price-lists', ['name' => 'Happy hour'])->assertStatus(201);
        $this->assertFalse($l->json('isDefault'));
        $o->patch("/catalog/price-lists/{$default['id']}", ['isDefault' => false], ['If-Match' => '"'.$default['rowVersion'].'"'])->assertStatus(409)->assertJsonPath('code', 'default_price_list_required');
        $o->patch("/catalog/price-lists/{$l->json('id')}", ['isDefault' => true], ['If-Match' => '"1"'])->assertOk()->assertJsonPath('isDefault', true);
        $this->assertSame(1, DB::table('price_list')->where('is_default', 1)->count());
        $this->assertNotEmpty($this->audit('config.catalog.price.create'));
        $this->assertNotEmpty(DB::table('outbox_event')->where('entity_type', 'Price')->get());
    }

    public function test_tax_rates_and_prep_routes_and_station_mapping(): void
    {
        $o = $this->owner();
        $t = $o->post('/catalog/tax-rates', ['code' => 'svc', 'name' => 'Service charge', 'ratePercent' => '10'])->assertStatus(201);
        $this->assertSame('SVC', $t->json('code'));
        $this->assertSame('10', $t->json('ratePercent'));
        $o->post('/catalog/tax-rates', ['code' => 'SVC', 'name' => 'dup', 'ratePercent' => '5'])->assertStatus(409)->assertJsonPath('code', 'tax_rate_code_taken');
        $o->post('/catalog/tax-rates', ['code' => 'BAD', 'name' => 'bad', 'ratePercent' => '150'])->assertStatus(422);
        $u = $o->patch("/catalog/tax-rates/{$t->json('id')}", ['ratePercent' => '12.5', 'name' => 'Service'], ['If-Match' => '"1"'])->assertOk();
        $this->assertSame('12.5', $u->json('ratePercent'));
        $o->patch("/catalog/tax-rates/{$t->json('id')}", ['active' => false], ['If-Match' => '"1"'])->assertStatus(412);
        $o->patch("/catalog/tax-rates/{$t->json('id')}", ['active' => false], ['If-Match' => '"2"'])->assertOk();
        $this->assertNotContains('SVC', array_column($o->get('/catalog/tax-rates')->json('items'), 'code'));
        $this->assertContains('SVC', array_column($o->get('/catalog/tax-rates?includeInactive=true')->json('items'), 'code'));

        $r = $o->post('/catalog/prep-routes', ['code' => 'pastry', 'name' => 'Pastry', 'kind' => 'kitchen'])->assertStatus(201);
        $this->assertSame('PASTRY', $r->json('code'));
        $o->post('/catalog/prep-routes', ['code' => 'PASTRY', 'name' => 'x', 'kind' => 'BAR'])->assertStatus(409);
        $fac = DemoIds::facility('CAFE');
        $station = DemoIds::operatingPoint('MAIN_KITCHEN', 'MAIN_KITCHEN');
        $st = $o->put('/catalog/prep-route-stations', ['facilityId' => $fac, 'prepRouteId' => $r->json('id'), 'kdsStationId' => $station])->assertOk();
        $this->assertSame($station, $st->json('kdsStationId'));
        $this->assertSame($station, collect($o->get("/catalog/prep-route-stations?facilityId={$fac}")->json('items'))->firstWhere('prepRouteId', $r->json('id'))['kdsStationId']);
        // a BAR-only station cannot serve the pastry route
        $o->put('/catalog/prep-route-stations', ['facilityId' => $fac, 'prepRouteId' => $r->json('id'), 'kdsStationId' => DemoIds::operatingPoint('POOL_BAR', 'POOL_BAR')])->assertStatus(422);
        $o->put('/catalog/prep-route-stations', ['facilityId' => $fac, 'prepRouteId' => $r->json('id'), 'kdsStationId' => null])->assertOk();
        $this->assertNull(collect($o->get("/catalog/prep-route-stations?facilityId={$fac}")->json('items'))->firstWhere('prepRouteId', $r->json('id')));
        $this->assertCount(2, $this->audit('config.catalog.prep_route_station.set'));

        // category default route applied to its products
        $p = $this->newProduct();
        $o->post("/catalog/categories/{$p['categoryId']}/prep-route", ['prepRouteId' => $r->json('id'), 'applyToProducts' => true])->assertOk();
        $this->assertSame($r->json('id'), $o->get("/admin/catalog/products/{$p['id']}")->json('prepRouteId'));
        // in-use route cannot change kind
        $o->patch("/catalog/prep-routes/{$r->json('id')}", ['kind' => 'BAR'], ['If-Match' => '"1"'])->assertStatus(409)->assertJsonPath('code', 'prep_route_in_use');
    }

    public function test_stock_links(): void
    {
        $o = $this->owner();
        $p = $this->newProduct(['trackStock' => true]);
        $item = Ids::fromBinary(DB::table('inventory_item')->value('id'));
        $o->put("/catalog/products/{$p['id']}/stock-links", ['links' => [['stockItemId' => $item, 'quantityPerUnit' => '0.25']]])->assertOk()->assertJsonPath('links.0.quantityPerUnit', '0.2500');
        $this->assertSame('0.2500', $o->get("/catalog/products/{$p['id']}/stock-links")->json('links.0.quantityPerUnit'));
        $o->put("/catalog/products/{$p['id']}/stock-links", ['links' => [['stockItemId' => Ids::uuid7(), 'quantityPerUnit' => '1']]])->assertStatus(422);
        $o->put("/catalog/products/{$p['id']}/stock-links", ['links' => [['stockItemId' => $item, 'quantityPerUnit' => '0']]])->assertStatus(422);
        $o->put("/catalog/products/{$p['id']}/stock-links", ['links' => [['stockItemId' => $item, 'quantityPerUnit' => '1'], ['stockItemId' => $item, 'quantityPerUnit' => '2']]])->assertStatus(422);
        $this->assertTrue($o->get('/admin/catalog/products?q='.$p['sku'])->json('items.0.hasStockLink'));
        $o->put("/catalog/products/{$p['id']}/stock-links", ['links' => []])->assertOk()->assertJsonPath('links', []);
        $this->assertNotEmpty($this->audit('config.catalog.stock_links.set', $p['id']));
    }

    public function test_admin_list_and_permissions(): void
    {
        $o = $this->owner();
        $list = $o->get('/admin/catalog/products?limit=5')->assertOk();
        $this->assertCount(5, $list->json('items'));
        $this->assertNotNull($list->json('nextCursor'));
        $this->assertArrayHasKey('defaultPrice', $list->json('items.0'));
        $this->assertGreaterThan(0, count($o->get('/admin/catalog/products?q=bread')->json('items')));
        $m = $this->managerLacking(['catalog.manage']);
        $m->get('/admin/catalog/products')->assertOk(); // pricing.manage is enough to read
        $m->post('/catalog/tax-rates', ['code' => 'NOPE', 'name' => 'n', 'ratePercent' => '1'])->assertStatus(403);
        $this->api('cashier1')->get('/admin/catalog/products')->assertStatus(403);
        $this->api('cashier1')->get('/catalog/products/export')->assertStatus(403);
    }

    public function test_csv_export_import_dry_run_and_apply(): void
    {
        $o = $this->owner();
        $csv = $o->get('/catalog/products/export')->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $body = $csv->getContent();
        $this->assertStringStartsWith('sku,name,category,kind,description,barcode,taxRateCode,prepRoute,trackStock,imageUrl,active,price', $body);
        $this->assertStringContainsString('BK-BRED', $body);

        // round trip: re-importing the export changes nothing
        $rt = $this->importRaw('/catalog/products/import?dryRun=true', $body)->assertOk();
        $this->assertSame(0, $rt->json('willCreate') + $rt->json('willUpdate'), json_encode($rt->json()));
        $this->assertSame([], $rt->json('errors'));
        $this->assertGreaterThan(5, $rt->json('unchanged'));

        $good = "sku,name,category,kind,description,barcode,price,active\nNEW-1,New burger,Mains,GOOD,Tasty,BC-9001,3500,true\nNEW-2,New shake,Brand new category,GOOD,,,1200.50,true\nNEW-9,,Mains,GOOD,,,,\n";
        // (a new product without a name is an error on the last row)
        $dry = $this->importRaw('/catalog/products/import', $good)->assertOk();
        $this->assertTrue($dry->json('dryRun'));
        $this->assertFalse($dry->json('applied'));
        $this->assertSame(2, $dry->json('valid'));
        $this->assertSame(2, $dry->json('willCreate'));
        $this->assertSame('name', $dry->json('errors.0.field'));
        $this->assertSame(4, $dry->json('errors.0.row'));
        $this->assertNull($this->sku('NEW-1'), 'dry run wrote nothing');
        $this->assertSame(0, DB::table('product_category')->where('name', 'Brand new category')->count());

        $ok = "sku,name,category,kind,description,barcode,price,active\nNEW-1,New burger,Mains,GOOD,Tasty,BC-9001,3500,true\nNEW-2,New shake,Brand new category,GOOD,,,1200.50,true\n";
        $bad = $this->importRaw('/catalog/products/import?dryRun=false', $ok."NEW-3,x,Mains,BOGUS,,,10,true\n")->assertStatus(422);
        $this->assertSame('import_validation_failed', $bad->json('code'));
        $this->assertSame('kind', $bad->json('report.errors.0.field'));
        $this->assertNull($this->sku('NEW-1'), 'all-or-nothing: nothing imported when any row fails');

        $applied = $this->importRaw('/catalog/products/import?dryRun=false', $ok)->assertOk();
        $this->assertTrue($applied->json('applied'));
        $this->assertContains('Brand new category', $applied->json('createdCategories'));
        $this->assertNotNull($this->sku('NEW-1'));
        $this->assertSame('BC-9001', $this->sku('NEW-1')->barcode);
        $this->assertSame('1200.5000', $this->importPriceOf('NEW-2'));
        $this->assertCount(1, $this->audit('config.catalog.products.import'));
        $again = $this->importRaw('/catalog/products/import?dryRun=false', $ok);
        $this->assertSame(200, $again->status(), $again->getContent());
        $this->assertSame(2, $again->json('unchanged'), 'naturally idempotent');
        $upd = $this->importRaw('/catalog/products/import?dryRun=false', "sku,name,price\nNEW-1,New burger XL,4000\n")->assertOk();
        $this->assertSame(1, $upd->json('willUpdate'));
        $this->assertSame('4000.0000', $this->importPriceOf('NEW-1'));
        // structural problems
        $this->importRaw('/catalog/products/import', "sku,nope\nA,B\n")->assertOk()->assertJsonPath('errors.0.field', 'nope');
        $o->post('/catalog/products/import', ['csv' => "sku,name,category\nJSON-1,From json,Mains\n"])->assertOk()->assertJsonPath('willCreate', 1);
    }

    public function test_price_csv_import_export(): void
    {
        $o = $this->owner();
        $exp = $o->get('/catalog/prices/export')->assertOk();
        $body = $exp->getContent();
        $this->assertStringStartsWith('sku,facilityCode,priceList,amount,validFrom,validTo', $body);
        $rt = $this->importRaw('/catalog/prices/import', $body)->assertOk();
        $this->assertSame([], $rt->json('errors'));
        $this->assertSame(0, $rt->json('willCreate'));

        $from = now('UTC')->addDays(30)->format('Y-m-d\TH:i:s\Z');
        $csv = "sku,facilityCode,amount,validFrom\nBK-BRED,CAFE,5200,{$from}\nBK-BRED,NOPE_FAC,1,{$from}\nNOSKU,,1,\n";
        $dry = $this->importRaw('/catalog/prices/import', $csv)->assertOk();
        $this->assertSame(1, $dry->json('willCreate'));
        $this->assertCount(2, $dry->json('errors'));
        $this->assertSame('facilityCode', $dry->json('errors.0.field'));
        $this->assertSame('sku', $dry->json('errors.1.field'));
        $before = DB::table('price')->count();
        $this->importRaw('/catalog/prices/import?dryRun=false', $csv)->assertStatus(422);
        $this->assertSame($before, DB::table('price')->count());
        $this->importRaw('/catalog/prices/import?dryRun=false', "sku,facilityCode,amount,validFrom\nBK-BRED,CAFE,5200,{$from}\n")->assertOk()->assertJsonPath('applied', true);
        $this->assertSame($before + 1, DB::table('price')->count());
        $this->importRaw('/catalog/prices/import?dryRun=false', "sku,facilityCode,amount,validFrom\nBK-BRED,CAFE,5200,{$from}\n")->assertOk()->assertJsonPath('unchanged', 1);
    }

    public function test_csv_formula_injection_guard(): void
    {
        $o = $this->owner();
        $cat = $o->get('/catalog/categories')->json('items.0.id');
        $o->post('/catalog/products', ['sku' => 'INJ-1', 'name' => '=HYPERLINK("http://evil")', 'categoryId' => $cat])->assertStatus(201);
        $exp = $o->get('/catalog/products/export');
        $body = $exp->getContent();
        $this->assertStringContainsString("'=HYPERLINK", $body);
        $this->assertStringNotContainsString(',=HYPERLINK', $body);
        $this->importRaw('/catalog/products/import?dryRun=false', $body)->assertOk();
        $this->assertSame('=HYPERLINK("http://evil")', $this->sku('INJ-1')->name, 'apostrophe stripped on the way back in');
    }

    private function importPriceOf(string $sku): ?string
    {
        return app(Pricing::class)->unitPrice(Ids::fromBinary($this->sku($sku)->id), DemoIds::facility('CAFE'));
    }

    private function importRaw(string $uri, string $csv)
    {
        $token = $this->owner()->auth['accessToken'];

        return $this->call('POST', '/api/v1'.$uri, [], [], [], ['CONTENT_TYPE' => 'text/csv', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], $csv);
    }
}
