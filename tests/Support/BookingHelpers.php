<?php

namespace Tests\Support;

use App\Domain\Booking\Models\AvailabilitySchedule;
use App\Domain\Booking\Models\BookableResource;
use App\Domain\Identity\Models\Staff;
use App\Domain\Organization\Models\FacilityUnit;
use Carbon\CarbonImmutable;

/** Shared fixtures for booking/ticketing tests (works in both TestCase and ConcurrentTestCase). */
trait BookingHelpers
{
    public const BOOKING_PERMS = ['booking.create', 'booking.view', 'booking.cancel', 'booking.reschedule', 'booking.configure', 'ticket.issue', 'ticket.view'];

    public const SCAN_PERMS = ['ticket.view', 'ticket.redeem', 'ticket.release'];

    /** @return array{t: array{org: string, site: string}, reception: FacilityUnit, arena: FacilityUnit, entrance: FacilityUnit, store: FacilityUnit, pool: FacilityUnit} */
    protected function world(): array
    {
        // These tests exercise booking rules with stated tenders; the Orders+Payments gateway has its own integration test.
        config(['booking.payment_gateway' => 'unlinked']);
        putenv('BOOKING_PAYMENT_GATEWAY=unlinked'); // inherited by the child processes of Concurrent::run()
        $_ENV['BOOKING_PAYMENT_GATEWAY'] = $_SERVER['BOOKING_PAYMENT_GATEWAY'] = 'unlinked';
        // ...but never leak it into the next test (an integrated flow such as MvpSmokeTest needs the real Orders+Payments gateway).
        $this->beforeApplicationDestroyed(function (): void {
            putenv('BOOKING_PAYMENT_GATEWAY');
            unset($_ENV['BOOKING_PAYMENT_GATEWAY'], $_SERVER['BOOKING_PAYMENT_GATEWAY']);
        });
        $t = TestData::tenant();
        $arena = TestData::facility($t, 'arena');

        return [
            't' => $t,
            'reception' => TestData::facility($t, 'reception'),
            'arena' => $arena,
            'entrance' => TestData::facility($t, 'entrance', $arena->id),
            'store' => TestData::facility($t, 'store'),
            'pool' => TestData::facility($t, 'pool'),
        ];
    }

    protected function resource(array $w, array $o = []): BookableResource
    {
        $r = BookableResource::create($o + [
            'organization_id' => $w['t']['org'], 'site_id' => $w['t']['site'], 'facility_unit_id' => $w['arena']->id,
            'code' => 'R'.substr(md5((string) microtime(true).random_int(0, 99999)), 0, 8), 'name' => 'Tennis Court 1', 'mode' => 'TIME_SLOT',
            'capacity' => 1, 'slot_minutes' => 60, 'max_slots_per_booking' => 4, 'price' => '5000.0000',
        ]);
        foreach (range(1, 7) as $dow) {
            AvailabilitySchedule::create(['resource_id' => $r->id, 'day_of_week' => $dow, 'open_time' => '07:00:00', 'close_time' => '21:00:00']);
        }

        return $r;
    }

    /** Staff holding exactly `$perms` at SITE scope; returns [Staff, bearer token]. @return array{0: Staff, 1: string} */
    protected function staffWith(array $w, string $username, array $perms): array
    {
        $staff = TestData::staff($w['t'], $username);
        TestData::assignRole($staff, TestData::customRole(strtoupper($username).'_ROLE', ucfirst($username), $perms), 'SITE');
        $token = $this->postJson('/api/v1/auth/staff/login', ['username' => $username, 'password' => TestData::PASSWORD])->json('accessToken');

        return [$staff, $token];
    }

    /** Slot on the resource grid: local (Africa/Lagos) hour on the day `$daysAhead` from now. @return array{0: string, 1: string} ISO UTC start/end */
    protected function slot(int $hour = 10, int $daysAhead = 2, int $hours = 1): array
    {
        $start = CarbonImmutable::now('Africa/Lagos')->addDays($daysAhead)->setTime($hour, 0)->utc();

        return [$start->format('Y-m-d\TH:i:s\Z'), $start->addHours($hours)->format('Y-m-d\TH:i:s\Z')];
    }

    protected function idem(string $token, array $extra = []): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'k-'.bin2hex(random_bytes(12)), 'Accept' => 'application/json'] + $extra;
    }
}
