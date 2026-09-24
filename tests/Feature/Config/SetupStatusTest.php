<?php

namespace Tests\Feature\Config;

use App\Support\Demo\DemoIds;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestData;
use Tests\Support\TestResponseBuilder;
use Tests\TestCase;

/** Empty property: the onboarding checklist starts at zero and follows real configuration steps. */
class SetupStatusTest extends TestCase
{
    private TestResponseBuilder $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $t = TestData::tenant('Fresh Resort');
        $staff = TestData::staff($t, 'freshowner', TestData::PASSWORD, '1234');
        TestData::assign($staff, 'OWNER', 'ORGANIZATION');
        $auth = $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'PIN', 'identifier' => 'freshowner', 'secret' => '1234'])->assertOk()->json();
        $this->owner = new TestResponseBuilder($this, $auth, null);
    }

    private function step(array $status, string $key): array
    {
        return collect($status['steps'])->firstWhere('key', $key);
    }

    public function test_empty_property_then_progress_as_things_are_configured(): void
    {
        $s0 = $this->owner->get('/admin/setup-status')->assertOk()->json();
        $this->assertFalse($s0['complete']);
        $this->assertContains('facilities', $s0['missing']);
        $this->assertContains('products', $s0['missing']);
        $this->assertSame(0, $s0['counts']['facilities']);
        $this->assertFalse($this->step($s0, 'facilities')['done']);
        $this->assertNotEmpty($this->step($s0, 'facilities')['hint']);
        // capability-dependent steps are not required on an empty property
        foreach (['kds_stations', 'payment_methods', 'booking_resources', 'tables'] as $k) {
            $this->assertFalse($this->step($s0, $k)['required'], $k);
        }

        $r = $this->owner->post('/organization/facilities', ['code' => 'RESTO', 'name' => 'Resto', 'templateKey' => 'RESTAURANT'])->assertStatus(201);
        $s1 = $this->owner->get('/admin/setup-status')->json();
        $this->assertTrue($this->step($s1, 'facilities')['done']);
        $this->assertSame(1, $s1['counts']['facilitiesConfigured']);
        $this->assertTrue($this->step($s1, 'payment_methods')['done']);
        $this->assertTrue($this->step($s1, 'kds_stations')['required'], 'kitchen routing is on now');
        $this->assertFalse($this->step($s1, 'kds_stations')['done']);
        $this->assertTrue($this->step($s1, 'tables')['required']);
        $this->assertGreaterThan($s0['percent'], $s1['percent']);

        // a KDS station, tables, a product with a price, receipt settings
        $this->owner->post('/organization/facilities/'.$r->json('id').'/operating-points', ['code' => 'PASS', 'name' => 'Pass', 'kind' => 'STATION', 'kdsStation' => ['kind' => 'KITCHEN']])->assertStatus(201);
        $this->owner->post('/organization/facilities/'.$r->json('id').'/tables/bulk', ['prefix' => 'T', 'from' => 1, 'to' => 5])->assertStatus(201);
        $cat = $this->owner->post('/catalog/categories', ['name' => 'Mains'])->json('id');
        $this->owner->post('/catalog/products', ['sku' => 'P1', 'name' => 'P1', 'categoryId' => $cat, 'price' => '100'])->assertStatus(201);
        $this->owner->put('/admin/settings/receipt', ['businessName' => 'Fresh'], ['If-Match' => '"0"'])->assertOk();
        $s2 = $this->owner->get('/admin/setup-status')->json();
        $this->assertTrue($this->step($s2, 'kds_stations')['done']);
        $this->assertTrue($this->step($s2, 'tables')['done']);
        $this->assertTrue($this->step($s2, 'products')['done']);
        $this->assertTrue($this->step($s2, 'prices')['done']);
        $this->assertTrue($this->step($s2, 'receipt_settings')['done']);
        $this->assertSame(1, $s2['counts']['products']);
        $this->assertSame(1, $s2['counts']['productsPriced']);
        $this->assertSame(5, $s2['counts']['tables']);
        $this->assertLessThan(100, $s2['percent']);
        // an unpriced product makes the prices step incomplete again
        $this->owner->post('/catalog/products', ['sku' => 'P2', 'name' => 'P2', 'categoryId' => $cat])->assertStatus(201);
        $s3 = $this->owner->get('/admin/setup-status')->json();
        $this->assertFalse($this->step($s3, 'prices')['done']);
        $this->assertStringContainsString('1 product', $this->step($s3, 'prices')['hint']);
    }

    public function test_requires_config_view(): void
    {
        $this->getJson('/api/v1/admin/setup-status')->assertStatus(401);
    }
}
