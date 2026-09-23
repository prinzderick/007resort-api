<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Services\BookingService;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\Support\BookingHelpers;
use Tests\TestCase;

class BookingFlowTest extends TestCase
{
    use BookingHelpers;

    private array $w;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = $this->world();
        [, $this->token] = $this->staffWith($this->w, 'rita', self::BOOKING_PERMS);
    }

    private function hold(string $resourceId, array $slot, array $extra = [], ?string $key = null)
    {
        $headers = $this->idem($this->token);
        if ($key) {
            $headers['Idempotency-Key'] = $key;
        }

        return $this->postJson('/api/v1/bookings/hold', ['resourceId' => $resourceId, 'start' => $slot[0], 'end' => $slot[1], 'customer' => ['name' => 'Chinedu Eze', 'phone' => '+2348012345678']] + $extra, $headers);
    }

    private function confirm(array $booking, string $amount = '5000.0000', ?string $ifMatch = null)
    {
        return $this->postJson("/api/v1/bookings/{$booking['id']}/confirm", ['tenders' => [['tenderType' => 'CASH', 'amount' => $amount, 'tendered' => $amount]]],
            $this->idem($this->token, ['If-Match' => $ifMatch ?? '"v'.$booking['rowVersion'].'"']));
    }

    public function test_hold_confirm_issues_entitlement_and_marks_slot_unavailable(): void
    {
        $r = $this->resource($this->w);
        $slot = $this->slot();

        $held = $this->hold($r->id, $slot)->assertStatus(201)->assertHeader('ETag')->assertJsonPath('status', 'HELD')->assertJsonPath('total', '5000.0000')->json();
        $this->assertNotNull($held['holdExpiresAt']);
        $this->assertMatchesRegularExpression('/^BK-\d{8}-\d{4}$/', $held['number']);

        $avail = $this->getJson("/api/v1/bookings/resources/{$r->id}/availability?from={$slot[0]}&to={$slot[1]}", $this->idem($this->token))->assertOk()->json('slots');
        $this->assertCount(1, $avail);
        $this->assertFalse($avail[0]['available']);
        $this->assertSame(0, $avail[0]['remainingCapacity']);

        $confirmed = $this->confirm($held)->assertOk()->assertJsonPath('status', 'CONFIRMED')->assertJsonPath('amountPaid', '5000.0000')->json();
        $this->assertNotNull($confirmed['entitlementId']);
        $this->assertNull($confirmed['holdExpiresAt']);

        $ent = $this->getJson("/api/v1/entitlements/{$confirmed['entitlementId']}", $this->idem($this->token))->assertOk()->json();
        $this->assertSame('ACTIVE', $ent['status']);
        $this->assertSame($held['id'], $ent['bookingId']);
        $this->assertSame('ACCESS', $ent['items'][0]['kind']);
        $this->assertSame('SINGLE_USE', $ent['items'][0]['validationMode']);
        $this->assertSame($this->w['arena']->id, $ent['items'][0]['facilityId']);
        $this->assertStringStartsWith('Tennis Court 1 - ', $ent['items'][0]['name']);
        $this->assertSame('CONFIRMED', DB::table('slot_allocation')->value('status'));
        // outbox + audit in the same transaction
        $this->assertTrue(DB::table('outbox_event')->where('event_type', 'BookingConfirmedLocally')->exists());
        $this->assertTrue(DB::table('audit_log')->where('action', 'booking.confirm')->exists());
        $this->assertSame(1, \App\Support\Audit\Audit::verifyChain()->valid ? 1 : 0);
    }

    public function test_second_hold_of_same_slot_is_409_slot_unavailable(): void
    {
        $r = $this->resource($this->w);
        $slot = $this->slot();
        $this->hold($r->id, $slot)->assertStatus(201);
        $this->hold($r->id, $slot)->assertStatus(409)->assertJsonPath('code', 'slot_unavailable');
        $this->hold($r->id, $this->slot(11))->assertStatus(201); // neighbouring slot is fine
    }

    public function test_hold_is_idempotent_on_replay_and_rejects_a_reused_key_with_a_different_body(): void
    {
        $r = $this->resource($this->w);
        $slot = $this->slot();
        $a = $this->hold($r->id, $slot, [], 'idem-hold-1')->assertStatus(201)->json();
        $b = $this->hold($r->id, $slot, [], 'idem-hold-1')->assertStatus(201)->assertHeader('Idempotent-Replayed', 'true')->json();
        $this->assertSame($a['id'], $b['id']);
        $this->assertSame(1, DB::table('booking')->count());
        $this->hold($r->id, $this->slot(12), [], 'idem-hold-1')->assertStatus(422);
    }

    public function test_slots_off_the_grid_or_outside_the_window_are_rejected(): void
    {
        $r = $this->resource($this->w);
        [$s] = $this->slot();
        $offGrid = [\Carbon\CarbonImmutable::parse($s)->addMinutes(30)->format('Y-m-d\TH:i:s\Z'), \Carbon\CarbonImmutable::parse($s)->addMinutes(90)->format('Y-m-d\TH:i:s\Z')];
        $this->hold($r->id, $offGrid)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->hold($r->id, $this->slot(3))->assertStatus(422); // 03:00 local: before opening hours
        $this->hold($r->id, $this->slot(10, 1, 5))->assertStatus(422); // 5 slots > max 4
        $this->hold($r->id, $this->slot(10, -1))->assertStatus(409)->assertJsonPath('code', 'slot_unavailable'); // in the past
    }

    public function test_blackout_blocks_hold_and_availability(): void
    {
        $r = $this->resource($this->w);
        $slot = $this->slot();
        $this->postJson("/api/v1/bookings/resources/{$r->id}/blackouts", ['start' => $slot[0], 'end' => $slot[1], 'reason' => 'Maintenance'], $this->idem($this->token))->assertStatus(201);
        $this->hold($r->id, $slot)->assertStatus(409)->assertJsonPath('code', 'slot_unavailable')->assertJsonPath('meta.reason', 'blackout');
        $slots = $this->getJson("/api/v1/bookings/resources/{$r->id}/availability?from={$slot[0]}&to={$slot[1]}", $this->idem($this->token))->json('slots');
        $this->assertFalse($slots[0]['available']);
    }

    public function test_per_unit_capacity_one_row_per_unit(): void
    {
        $r = $this->resource($this->w, ['mode' => 'INDIVIDUAL_CAPACITY', 'capacity' => 3, 'price' => '2000.0000', 'allow_whole_resource' => true]);
        $slot = $this->slot();

        $a = $this->hold($r->id, $slot, ['quantity' => 2])->assertStatus(201)->assertJsonPath('total', '4000.0000')->assertJsonPath('quantity', 2)->json();
        $this->assertSame(2, DB::table('slot_allocation')->count());
        $this->assertEqualsCanonicalizing([1, 2], DB::table('slot_allocation')->pluck('unit_no')->map(fn ($u) => (int) $u)->all());

        $avail = $this->getJson("/api/v1/bookings/resources/{$r->id}/availability?from={$slot[0]}&to={$slot[1]}", $this->idem($this->token))->json('slots.0');
        $this->assertTrue($avail['available']);
        $this->assertSame(1, $avail['remainingCapacity']);

        $this->hold($r->id, $slot, ['quantity' => 2])->assertStatus(409)->assertJsonPath('code', 'slot_unavailable'); // only 1 left
        $this->hold($r->id, $slot, ['quantity' => 1])->assertStatus(201);
        $this->hold($r->id, $slot, ['quantity' => 1])->assertStatus(409);
        $this->hold($r->id, $slot, ['quantity' => 4])->assertStatus(409)->assertJsonPath('meta.reason', 'capacity_exceeded');

        // combined mode: a whole-resource booking needs ALL units free
        $this->hold($r->id, $slot, ['wholeResource' => true])->assertStatus(409);
        $other = $this->slot(14);
        $whole = $this->hold($r->id, $other, ['wholeResource' => true])->assertStatus(201)->assertJsonPath('quantity', 3)->assertJsonPath('total', '6000.0000')->json();
        $this->assertSame(3, DB::table('slot_allocation')->where('slot_start', \Carbon\CarbonImmutable::parse($other[0])->format('Y-m-d H:i:s.u'))->count());
        $this->hold($r->id, $other, ['quantity' => 1])->assertStatus(409); // whole-resource booking blocks per-unit booking
        $this->assertNotNull($a['id'] . $whole['id']);
    }

    public function test_multi_slot_booking_claims_every_slot_and_prices_them(): void
    {
        $r = $this->resource($this->w);
        $two = $this->slot(10, 2, 2);
        $this->hold($r->id, $two)->assertStatus(201)->assertJsonPath('total', '10000.0000');
        $this->assertSame(2, DB::table('slot_allocation')->count());
        $this->hold($r->id, $this->slot(11))->assertStatus(409); // second hour of the two-hour booking
    }

    public function test_expired_hold_frees_the_slot_via_sweeper_and_lazily(): void
    {
        $r = $this->resource($this->w);
        $slot = $this->slot();
        $held = $this->hold($r->id, $slot)->assertStatus(201)->json();
        $this->hold($r->id, $slot)->assertStatus(409);

        DB::table('booking')->where('id', Ids::toBinary($held['id']))->update(['hold_expires_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s.u')]);
        DB::table('slot_allocation')->update(['hold_expires_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s.u')]);

        // confirming an expired hold fails
        $this->confirm($held)->assertStatus(409)->assertJsonPath('code', 'hold_expired');

        // lazy path: a new hold takes over the unswept expired slot
        $second = $this->hold($r->id, $slot)->assertStatus(201)->json();
        $this->assertSame('EXPIRED', DB::table('booking')->where('id', Ids::toBinary($held['id']))->value('status'));
        $this->assertSame(1, DB::table('slot_allocation')->count());

        // sweeper path
        DB::table('booking')->where('id', Ids::toBinary($second['id']))->update(['hold_expires_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s.u')]);
        $this->assertSame(1, app(BookingService::class)->expireDue());
        $this->assertSame(0, DB::table('slot_allocation')->count());
        $this->assertSame('EXPIRED', DB::table('booking')->where('id', Ids::toBinary($second['id']))->value('status'));
        $this->hold($r->id, $slot)->assertStatus(201);
        $this->assertSame(0, app(BookingService::class)->expireDue()); // nothing else due; confirmed/held-live untouched
    }

    public function test_confirm_requires_if_match_matching_version_and_exact_payment(): void
    {
        $r = $this->resource($this->w);
        $held = $this->hold($r->id, $this->slot())->assertStatus(201)->json();
        $this->postJson("/api/v1/bookings/{$held['id']}/confirm", ['tenders' => [['tenderType' => 'CASH', 'amount' => '5000.0000']]], $this->idem($this->token))->assertStatus(428);
        $this->confirm($held, '5000.0000', '"v99"')->assertStatus(412)->assertJsonPath('code', 'concurrency_conflict');
        $this->confirm($held, '4000.0000')->assertStatus(422)->assertJsonPath('code', 'amount_mismatch');
        $this->assertSame('HELD', DB::table('booking')->value('status')); // failed attempts change nothing
        $this->confirm($held)->assertOk();
        $this->confirm(['id' => $held['id'], 'rowVersion' => $held['rowVersion'] + 1])->assertStatus(409)->assertJsonPath('code', 'booking_state_invalid');
    }

    public function test_cancel_releases_slot_and_cancels_entitlement_with_fee_inside_cutoff(): void
    {
        $r = $this->resource($this->w);
        $slot = $this->slot();
        $held = $this->hold($r->id, $slot)->assertStatus(201)->json();
        $conf = $this->confirm($held)->assertOk()->json();

        $cancelled = $this->postJson("/api/v1/bookings/{$conf['id']}/cancel", ['reason' => 'Rain'], $this->idem($this->token, ['If-Match' => '"v'.$conf['rowVersion'].'"']))
            ->assertOk()->assertJsonPath('status', 'CANCELLED')->assertJsonPath('cancellationFee', '0.0000')->json();
        $this->assertSame(0, DB::table('slot_allocation')->count());
        $this->assertSame('CANCELLED', DB::table('entitlement')->value('status'));
        $this->assertTrue(DB::table('outbox_event')->where('event_type', 'BookingCancelled')->exists());
        $this->hold($r->id, $slot)->assertStatus(201); // slot is free again

        $this->postJson("/api/v1/bookings/{$conf['id']}/cancel", ['reason' => 'again'], $this->idem($this->token, ['If-Match' => '"v'.$cancelled['rowVersion'].'"']))
            ->assertStatus(409)->assertJsonPath('code', 'booking_state_invalid');

        // inside the cutoff a configured fee applies
        DB::table('booking_rule')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'organization_id' => Ids::toBinary($this->w['t']['org']), 'resource_id' => Ids::toBinary($r->id), 'cancel_cutoff_minutes' => 100000, 'cancel_fee_percent' => '20.00']);
        app(\App\Domain\Booking\Services\BookingRules::class)->forget();
        $held2 = $this->hold($r->id, $this->slot(15))->assertStatus(201)->json();
        $conf2 = $this->confirm($held2)->assertOk()->json();
        $this->postJson("/api/v1/bookings/{$conf2['id']}/cancel", ['reason' => 'late'], $this->idem($this->token, ['If-Match' => '"v'.$conf2['rowVersion'].'"']))
            ->assertOk()->assertJsonPath('cancellationFee', '1000.0000');
    }

    public function test_reschedule_moves_allocation_and_entitlement_window_atomically(): void
    {
        $r = $this->resource($this->w);
        $a = $this->hold($r->id, $this->slot(10))->assertStatus(201)->json();
        $conf = $this->confirm($a)->assertOk()->json();
        $blocker = $this->hold($r->id, $this->slot(12))->assertStatus(201)->json(); // someone else holds 12:00

        // target taken -> 409 and nothing changes (old slot still ours)
        $this->postJson("/api/v1/bookings/{$conf['id']}/reschedule", ['start' => $this->slot(12)[0], 'end' => $this->slot(12)[1]], $this->idem($this->token, ['If-Match' => '"v'.$conf['rowVersion'].'"']))
            ->assertStatus(409)->assertJsonPath('code', 'slot_unavailable');
        $this->assertSame(1, DB::table('slot_allocation')->where('booking_item_id', DB::table('booking_item')->where('booking_id', Ids::toBinary($conf['id']))->value('id'))->count());
        $this->assertSame(0, (int) DB::table('booking')->where('id', Ids::toBinary($conf['id']))->value('reschedule_count'));

        $moved = $this->postJson("/api/v1/bookings/{$conf['id']}/reschedule", ['start' => $this->slot(11)[0], 'end' => $this->slot(11)[1], 'reason' => 'Customer request'],
            $this->idem($this->token, ['If-Match' => '"v'.$conf['rowVersion'].'"']))->assertOk()->assertJsonPath('status', 'CONFIRMED')->json();
        $this->assertSame($this->slot(11)[0], $moved['start']);
        $this->assertSame(1, (int) DB::table('booking')->where('id', Ids::toBinary($conf['id']))->value('reschedule_count'));
        $ent = $this->getJson("/api/v1/entitlements/{$conf['entitlementId']}", $this->idem($this->token))->json('items.0');
        $this->assertSame(\Carbon\CarbonImmutable::parse($this->slot(11)[1])->format('Y-m-d\TH:i:s\Z'), $ent['validUntil']);
        $this->hold($r->id, $this->slot(10))->assertStatus(201); // the old slot was released
        $this->assertNotNull($blocker['id']);

        // duration must not change
        $this->postJson("/api/v1/bookings/{$conf['id']}/reschedule", ['start' => $this->slot(14)[0], 'end' => $this->slot(14, 2, 2)[1]], $this->idem($this->token, ['If-Match' => '"v'.$moved['rowVersion'].'"']))
            ->assertStatus(422);
    }

    public function test_list_and_get_bookings_with_filters_and_cursor_shape(): void
    {
        $r = $this->resource($this->w);
        foreach ([10, 11, 12] as $h) {
            $this->hold($r->id, $this->slot($h))->assertStatus(201);
        }
        $page = $this->getJson("/api/v1/bookings?filter[resourceId]={$r->id}&limit=2", $this->idem($this->token))->assertOk()->json();
        $this->assertCount(2, $page['items']);
        $this->assertNotNull($page['nextCursor']);
        $rest = $this->getJson("/api/v1/bookings?filter[resourceId]={$r->id}&limit=2&cursor={$page['nextCursor']}", $this->idem($this->token))->json();
        $this->assertCount(1, $rest['items']);
        $this->assertNull($rest['nextCursor']);
        $this->getJson('/api/v1/bookings?filter[status]=CONFIRMED', $this->idem($this->token))->assertOk()->assertJsonCount(0, 'items');
        $this->getJson("/api/v1/bookings/{$page['items'][0]['id']}", $this->idem($this->token))->assertOk()->assertHeader('ETag');
        $this->getJson('/api/v1/bookings/'.Ids::uuid7(), $this->idem($this->token))->assertStatus(404);
        $res = $this->getJson("/api/v1/bookings/resources?facilityId={$this->w['arena']->id}", $this->idem($this->token))->assertOk()->json();
        $this->assertSame($r->id, $res['items'][0]['id']);
        $this->assertSame('5000.0000', $res['items'][0]['price']);
    }

    public function test_permissions_are_enforced_by_code_not_role_name(): void
    {
        $r = $this->resource($this->w);
        [, $viewer] = $this->staffWith($this->w, 'viewer', ['booking.view']);
        $this->postJson('/api/v1/bookings/hold', ['resourceId' => $r->id, 'start' => $this->slot()[0], 'end' => $this->slot()[1]], $this->idem($viewer))->assertStatus(403)->assertJsonPath('permission', 'booking.create');
        $this->postJson('/api/v1/bookings/resources', ['facilityId' => $this->w['arena']->id, 'code' => 'X', 'name' => 'X', 'mode' => 'TIME_SLOT'], $this->idem($viewer))->assertStatus(403);
        $this->getJson('/api/v1/bookings', ['Accept' => 'application/json'])->assertStatus(401);
    }

    public function test_manager_can_configure_resource_and_authority_strategy(): void
    {
        $body = ['facilityId' => $this->w['arena']->id, 'code' => 'CLINIC', 'name' => 'Clinic', 'mode' => 'INDIVIDUAL_CAPACITY', 'capacity' => 10, 'price' => '1500.0000',
            'authority' => ['offlineStrategy' => 'A_OFFLINE_ALLOCATION', 'localReserveUnits' => 3]];
        $id = $this->postJson('/api/v1/bookings/resources', $body, $this->idem($this->token))->assertStatus(201)->assertJsonPath('authority.localReserveUnits', 3)->json('id');
        $this->patchJson("/api/v1/bookings/resources/{$id}", ['authority' => ['offlineStrategy' => 'B_ONLINE_AUTHORITY_REQUIRED']], $this->idem($this->token))
            ->assertOk()->assertJsonPath('authority.offlineStrategy', 'B_ONLINE_AUTHORITY_REQUIRED')->assertJsonPath('rowVersion', 2);
        $this->patchJson("/api/v1/bookings/resources/{$id}", ['authority' => ['localReserveUnits' => 11]], $this->idem($this->token))->assertStatus(422);
        $this->assertTrue(DB::table('audit_log')->where('action', 'booking.resource.update')->exists());
    }
}
