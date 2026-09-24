<?php

namespace Tests\Feature\Config;

use App\Support\Demo\DemoIds;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

class SettingsTest extends ConfigTestCase
{
    public function test_business_profile_read_update_and_concurrency(): void
    {
        $o = $this->owner();
        $b = $o->get('/admin/settings/business')->assertOk()->assertHeader('ETag');
        $this->assertSame('Africa/Lagos', $b->json('timezone'));
        $this->assertSame('NGN', $b->json('currency'));
        $v = $b->json('rowVersion');

        $o->put('/admin/settings/business', ['siteName' => 'x'], [])->assertStatus(428);
        $u = $o->put('/admin/settings/business', ['organizationName' => '007 Resort Ltd', 'siteName' => '007 Resort & Spa, Otueke', 'timezone' => 'Africa/Accra', 'address' => '1 Resort Road', 'phone' => '+2348000000001', 'email' => 'hello@007resort.test'], ['If-Match' => '"'.$v.'"'])->assertOk();
        $this->assertSame('007 Resort Ltd', $u->json('organizationName'));
        $this->assertSame('Africa/Accra', $u->json('timezone'));
        $this->assertSame($v + 1, $u->json('rowVersion'));
        $this->assertSame('007 Resort Ltd', DB::table('organization')->value('name'));
        $o->put('/admin/settings/business', ['siteName' => 'stale'], ['If-Match' => '"'.$v.'"'])->assertStatus(412);
        $o->put('/admin/settings/business', ['timezone' => 'Mars/Base'], ['If-Match' => '"'.($v + 1).'"'])->assertStatus(422)->assertJsonValidationErrors(['timezone']);
        $o->put('/admin/settings/business', ['email' => 'nope'], ['If-Match' => '"'.($v + 1).'"'])->assertStatus(422);
        $o->put('/admin/settings/business', ['currency' => 'USD'], ['If-Match' => '"'.($v + 1).'"'])->assertStatus(422);
        // no-op keeps the version
        $o->put('/admin/settings/business', ['siteName' => '007 Resort & Spa, Otueke'], ['If-Match' => '"'.($v + 1).'"'])->assertOk()->assertHeader('ETag', '"'.($v + 1).'"');
        $a = $this->audit('config.business.update')[0];
        $this->assertSame('Africa/Lagos', $a->old['timezone']);
        $this->assertSame('Africa/Accra', $a->new['timezone']);
        $this->assertNotEmpty($this->outbox(DemoIds::site(), 'businessProfile'));
    }

    public function test_receipt_settings(): void
    {
        $o = $this->owner();
        $r = $o->get('/admin/settings/receipt')->assertOk();
        $this->assertSame(0, $r->json('rowVersion'));
        $this->assertNotSame('', $r->json('effective.businessName'));
        $this->assertFalse($r->json('vatRegistered'));

        $u = $o->put('/admin/settings/receipt', ['businessName' => '007 Resort & Spa', 'address' => 'Otueke, Bayelsa', 'phone' => '+2348000000001', 'footer' => 'See you again!', 'logoUrl' => 'https://cdn.example.com/logo.png', 'showTin' => false, 'paperColumns' => 32],
            ['If-Match' => '"0"'])->assertOk();
        $this->assertSame(1, $u->json('rowVersion'));
        $this->assertSame('See you again!', $u->json('effective.footer'));
        $this->assertSame(32, $u->json('paperColumns'));
        $o->put('/admin/settings/receipt', ['footer' => 'x'], ['If-Match' => '"0"'])->assertStatus(412);
        $o->put('/admin/settings/receipt', ['paperColumns' => 40], ['If-Match' => '"1"'])->assertStatus(422);
        $o->put('/admin/settings/receipt', ['logoUrl' => 'javascript:alert(1)'], ['If-Match' => '"1"'])->assertStatus(422);
        $two = $o->put('/admin/settings/receipt', ['footer' => 'Come back soon'], ['If-Match' => '"1"'])->assertOk();
        $this->assertSame(2, $two->json('rowVersion'));
        $this->assertCount(2, $this->audit('config.receipt.update'));
        $this->assertSame([1, 2], array_column($this->outbox(DemoIds::org(), 'receiptSetting'), 'version'));
    }

    public function test_payment_methods_per_facility(): void
    {
        $o = $this->owner();
        $rest = DemoIds::facility('RESTAURANT');
        $g = $o->get("/facilities/{$rest}/payment-methods")->assertOk()->assertHeader('ETag');
        $this->assertFalse($g->json('restricted'));
        $this->assertTrue($g->json('methods.CARD'));
        $v = $g->json('version');
        $u = $o->put("/facilities/{$rest}/payment-methods", ['methods' => ['CARD' => false, 'TRANSFER' => false]], ['If-Match' => '"'.$v.'"'])->assertOk();
        $this->assertTrue($u->json('restricted'));
        $this->assertFalse($u->json('methods.CARD'));
        $this->assertTrue($u->json('methods.CASH'));
        $this->assertSame($v + 1, $u->json('version'));
        $o->put("/facilities/{$rest}/payment-methods", ['methods' => ['BITCOIN' => true]], ['If-Match' => '"'.($v + 1).'"'])->assertStatus(422);
        $o->put("/facilities/{$rest}/payment-methods", ['methods' => ['CASH' => false, 'POS_TERMINAL' => false]], ['If-Match' => '"'.($v + 1).'"'])->assertStatus(422)->assertJsonValidationErrors(['methods']);
        $o->put("/facilities/{$rest}/payment-methods", ['methods' => ['CARD' => true]], ['If-Match' => '"'.$v.'"'])->assertStatus(412);
        // re-enabling everything drops the restriction
        $all = $o->put("/facilities/{$rest}/payment-methods", ['methods' => ['CARD' => true, 'TRANSFER' => true]], ['If-Match' => '"'.($v + 1).'"'])->assertOk();
        $this->assertFalse($all->json('restricted'));
        $this->assertSame(0, DB::table('facility_payment_method')->count());
        $this->assertCount(2, $this->audit('config.payment_methods.update', $rest));
        $this->assertSame([$v + 1, $v + 2], array_column($this->outbox($rest, 'facilityPaymentMethods'), 'version'));
    }

    public function test_permissions(): void
    {
        $noSettings = $this->managerLacking(['settings.manage']);
        $noSettings->put('/admin/settings/business', ['siteName' => 'x'], ['If-Match' => '"1"'])->assertStatus(403)->assertJsonPath('permission', 'settings.manage');
        $noSettings->put('/admin/settings/receipt', ['footer' => 'x'], ['If-Match' => '"0"'])->assertStatus(403);
        $noSettings->put('/facilities/'.DemoIds::facility('CAFE').'/payment-methods', ['methods' => ['CARD' => false]], ['If-Match' => '"1"'])->assertStatus(403);
        $noSettings->get('/admin/settings/business')->assertOk();
        $this->api('cashier1')->get('/admin/settings/business')->assertStatus(403);
        $this->assertNotNull(Ids::uuid7());
    }
}
