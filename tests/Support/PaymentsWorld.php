<?php

namespace Tests\Support;

use App\Domain\Payments\Support\FacilityRules;
use App\Domain\Payments\Support\Ledger;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * Fixtures for Payments tests: one tenant, one facility, a cashier / supervisor / manager with real tokens, and helpers to
 * create committed orders, tabs, cash sessions and Paystack payloads. Uses the real schema (Orders' `order` / `tab` tables).
 * Must be used from a class that has `postJson` (TestCase / ConcurrentTestCase).
 */
trait PaymentsWorld
{
    protected array $t;

    protected string $facility;

    protected object $cashier;

    protected object $supervisor;

    protected object $manager;

    protected string $cashierToken;

    protected string $supervisorToken;

    protected string $managerToken;

    private int $orderSeq = 0;

    protected function buildWorld(): void
    {
        $this->t = TestData::tenant();
        $this->facility = TestData::facility($this->t, 'restaurant')->id;
        $this->cashier = TestData::staff($this->t, 'cashier1');
        TestData::assign($this->cashier, 'CASHIER', 'SITE');
        $this->supervisor = TestData::staff($this->t, 'super1');
        TestData::assign($this->supervisor, 'UNIT_SUPERVISOR', 'SITE');
        $this->manager = TestData::staff($this->t, 'manager1');
        TestData::assign($this->manager, 'MANAGER', 'SITE');
        $this->cashierToken = $this->loginToken('cashier1');
        $this->supervisorToken = $this->loginToken('super1');
        $this->managerToken = $this->loginToken('manager1');
    }

    protected function loginToken(string $user): string
    {
        return (string) $this->postJson('/api/v1/auth/staff/login', ['username' => $user, 'password' => TestData::PASSWORD])->json('accessToken');
    }

    /** @return array<string, string> */
    protected function auth(string $token, ?string $key = 'auto'): array
    {
        $h = ['Authorization' => 'Bearer '.$token];
        if ($key !== null) {
            $h['Idempotency-Key'] = $key === 'auto' ? 'k-'.Ids::uuid7() : $key;
        }

        return $h;
    }

    /** Create a committed order with a total (and a single line so receipts have content). */
    protected function makeOrder(string $total, string $status = 'SERVED', ?string $tabId = null, ?string $facility = null, string $tax = '0.0000'): string
    {
        $id = Ids::uuid7();
        $facility ??= $this->facility;
        $this->orderSeq++;
        DB::table('order')->insert([
            'id' => Ids::toBinary($id), 'organization_id' => Ids::toBinary($this->t['org']), 'site_id' => Ids::toBinary($this->t['site']),
            'facility_unit_id' => Ids::toBinary($facility), 'order_number' => 'T'.strtoupper(substr(str_replace('-', '', $id), -10)),
            'status' => $status, 'subtotal' => bcsub($total, $tax, 4), 'tax_total' => $tax, 'total' => $total, 'tab_id' => $tabId ? Ids::toBinary($tabId) : null,
            'created_by' => Ids::toBinary($this->cashier->id),
        ]);
        $this->orderLine($id, 'Jollof rice', 1, $total);

        return $id;
    }

    protected function orderLine(string $orderId, string $name, int $qty, string $lineTotal): void
    {
        static $product = null;
        if ($product === null || ! DB::table('product')->where('id', $product)->exists()) {
            $cat = Ids::toBinary(Ids::uuid7());
            DB::table('product_category')->insert(['id' => $cat, 'organization_id' => Ids::toBinary($this->t['org']), 'name' => 'Food']);
            $product = Ids::toBinary(Ids::uuid7());
            DB::table('product')->insert(['id' => $product, 'organization_id' => Ids::toBinary($this->t['org']), 'category_id' => $cat, 'sku' => 'SKU'.mt_rand(), 'name' => 'Item']);
        }
        $no = (int) DB::table('order_line')->where('order_id', Ids::toBinary($orderId))->max('line_no') + 1;
        DB::table('order_line')->insert([
            'id' => Ids::toBinary(Ids::uuid7()), 'order_id' => Ids::toBinary($orderId), 'product_id' => $product, 'line_no' => $no, 'product_name' => $name,
            'sku' => 'S', 'quantity' => $qty, 'unit_price' => bcdiv($lineTotal, (string) $qty, 4), 'line_total' => $lineTotal, 'gross_amount' => $lineTotal,
        ]);
    }

    protected function makeTab(?string $facility = null): string
    {
        $id = Ids::uuid7();
        DB::table('tab')->insert([
            'id' => Ids::toBinary($id), 'organization_id' => Ids::toBinary($this->t['org']), 'site_id' => Ids::toBinary($this->t['site']),
            'facility_unit_id' => Ids::toBinary($facility ?? $this->facility), 'opened_by' => Ids::toBinary($this->cashier->id),
        ]);

        return $id;
    }

    /** Open a cash session for a staff member directly in the DB (fast path for tests that are not about opening). */
    protected function openSession(?object $staff = null, string $float = '5000.0000', ?string $facility = null): string
    {
        $staff ??= $this->cashier;
        $id = Ids::uuid7();
        DB::table('cash_session')->insert([
            'id' => Ids::toBinary($id), 'organization_id' => Ids::toBinary($this->t['org']), 'site_id' => Ids::toBinary($this->t['site']),
            'facility_unit_id' => Ids::toBinary($facility ?? $this->facility), 'staff_id' => Ids::toBinary($staff->id), 'opening_float' => $float,
        ]);

        return $id;
    }

    protected function setRule(string $key, string $value, ?string $facility = null): void
    {
        $facility ??= $this->facility;
        $cap = DB::table('facility_capability')->where('facility_unit_id', Ids::toBinary($facility))->first();
        if ($cap === null) {
            $code = DB::table('capability_type')->value('code');
            $capId = Ids::toBinary(Ids::uuid7());
            DB::table('facility_capability')->insert(['id' => $capId, 'facility_unit_id' => Ids::toBinary($facility), 'capability_code' => $code]);
        } else {
            $capId = $cap->id;
        }
        DB::table('operating_rule')->updateOrInsert(
            ['facility_capability_id' => $capId, 'rule_key' => $key],
            ['id' => Ids::toBinary(Ids::uuid7()), 'rule_value' => $value],
        );
        app(FacilityRules::class)->forget();
    }

    protected function setVat(bool $on, string $tin = 'TIN-12345678'): void
    {
        DB::table('organization_tax_setting')->updateOrInsert(
            ['organization_id' => Ids::toBinary($this->t['org'])],
            ['vat_enabled' => $on ? 1 : 0, 'vat_rate_percent' => '7.5', 'vat_number' => $on ? $tin : null],
        );
    }

    /** @param list<array<string, mixed>> $allocs @param list<array<string, mixed>> $tenders */
    protected function payBody(array $allocs, array $tenders, ?string $session = null, array $extra = []): array
    {
        return array_filter([
            'facilityId' => $this->facility, 'cashSessionId' => $session,
            'allocations' => $allocs, 'tenders' => $tenders,
        ] + $extra, fn ($v) => $v !== null);
    }

    protected function paidOf(string $orderId): string
    {
        return Ledger::paidByOrder([$orderId])[$orderId];
    }

    protected function orderStatus(string $orderId): string
    {
        return (string) DB::table('order')->where('id', Ids::toBinary($orderId))->value('status');
    }
}
