<?php

namespace Tests\Support;

use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Services\DeviceTokenService;
use App\Domain\Orders\Services\OperatingRules;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * Waiter-collection fixtures on top of {@see PaymentsWorld}: two waiters (WAIT_STAFF, permission `payment.collect`), each with a tablet
 * checked out to them at the facility, a cashier (confirm) and a supervisor, a billed order helper and request helpers.
 */
trait CollectionWorld
{
    use PaymentsWorld;

    protected object $waiter;

    protected object $waiter2;

    protected string $waiterToken;

    protected string $waiter2Token;

    protected string $deviceId;

    protected string $device2Id;

    protected string $deviceToken;

    protected string $device2Token;

    protected function buildCollectionWorld(bool $cashHolding = false): void
    {
        $this->buildWorld();
        $this->waiter = TestData::staff($this->t, 'waiter1');
        TestData::assign($this->waiter, 'WAIT_STAFF', 'SITE');
        $this->waiter2 = TestData::staff($this->t, 'waiter2');
        TestData::assign($this->waiter2, 'WAIT_STAFF', 'SITE');
        $this->waiterToken = $this->loginToken('waiter1');
        $this->waiter2Token = $this->loginToken('waiter2');
        [$this->deviceId, $this->deviceToken] = $this->makeTablet('tab1', $this->waiter);
        [$this->device2Id, $this->device2Token] = $this->makeTablet('tab2', $this->waiter2);
        $this->setRule('waiter_collection_enabled', 'true');
        if ($cashHolding) {
            $this->setRule('waiter_cash_holding', 'true');
        }
    }

    /** @return array{0: string, 1: string} device id, plaintext token */
    protected function makeTablet(string $name, object $staff, ?string $facility = null): array
    {
        $device = Device::query()->create([
            'organization_id' => $this->t['org'], 'site_id' => $this->t['site'], 'facility_unit_id' => $facility ?? $this->facility, 'device_type' => 'TABLET',
            'mode' => 'ATTENDANT', 'name' => $name, 'hardware_id' => 'hw-'.$name.mt_rand(), 'platform' => 'android', 'app_version' => '0.1.0', 'is_active' => 1, 'is_revoked' => 0,
        ]);
        $token = 'r7d_test_'.bin2hex(random_bytes(12));
        app(DeviceTokenService::class)->store($device, $token, null);
        DB::table('tablet_checkout')->insert([
            'id' => Ids::toBinary(Ids::uuid7()), 'organization_id' => Ids::toBinary($this->t['org']), 'site_id' => Ids::toBinary($this->t['site']),
            'device_id' => Ids::toBinary($device->id), 'staff_id' => Ids::toBinary($staff->id), 'facility_unit_id' => Ids::toBinary($facility ?? $this->facility),
            'checked_out_by' => Ids::toBinary($staff->id),
        ]);

        return [$device->id, $token];
    }

    /** @return array<string, string> headers for a waiter request on their tablet (fresh Idempotency-Key unless $key is null) */
    protected function waiterAuth(string $token, string $deviceToken, ?string $key = 'auto'): array
    {
        return $this->auth($token, $key) + ['X-Device-Token' => $deviceToken];
    }

    /** Login token of a new staff member holding a custom role with exactly these permissions (role NAME is irrelevant: permissions decide). */
    protected function roleToken(string $username, array $permissions, string $roleName = 'Manager'): string
    {
        $role = TestData::customRole(strtoupper($username).'_R', $roleName, $permissions);
        TestData::assignRole(TestData::staff($this->t, $username), $role);

        return $this->loginToken($username);
    }

    protected function ownerToken(): string
    {
        TestData::assign(TestData::staff($this->t, 'owner1'), 'OWNER', 'ORGANIZATION');

        return $this->loginToken('owner1');
    }

    protected function setRuleFlush(string $key, string $value, ?string $facility = null): void
    {
        $this->setRule($key, $value, $facility);
        app(OperatingRules::class)->flush();
    }

    /** A SERVED order whose bill has been printed by the waiter (frozen, awaiting payment). */
    protected function billedOrder(string $total = '9000.0000', string $status = 'SERVED'): string
    {
        $order = $this->makeOrder($total, $status);
        $this->postJson("/api/v1/orders/{$order}/bill", [], $this->auth($this->waiterToken))->assertOk();

        return $order;
    }

    /** @param array<string, mixed> $body */
    protected function collect(string $order, array $body, ?string $token = null, ?string $deviceToken = null, ?string $key = 'auto')
    {
        return $this->postJson("/api/v1/orders/{$order}/collections", $body, $this->waiterAuth($token ?? $this->waiterToken, $deviceToken ?? $this->deviceToken, $key));
    }

    protected function paymentRow(string $id): object
    {
        return DB::table('payment')->where('id', Ids::toBinary($id))->first();
    }

    protected function paymentStatus(string $id): string
    {
        return (string) $this->paymentRow($id)->status;
    }
}
