<?php

namespace Tests\Feature\Ticketing;

use App\Domain\Ticketing\Contracts\RentalStockHook;
use App\Domain\Ticketing\Models\Entitlement;
use App\Domain\Ticketing\Services\EntitlementService;
use App\Domain\Ticketing\Services\RedemptionService;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\BookingHelpers;
use Tests\Support\TestData;
use Tests\TestCase;

class RedemptionTest extends TestCase
{
    use BookingHelpers;

    private array $w;

    private string $scan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = $this->world();
        [, $this->scan] = $this->staffWith($this->w, 'gate', array_unique([...self::SCAN_PERMS, 'ticket.override']));
    }

    /** Issue an entitlement straight through the service. @param list<array<string, mixed>> $items */
    private function ticket(array $items, ?string $key = null, string $holder = 'Ada Obi'): Entitlement
    {
        return app(EntitlementService::class)->issue($key ?? 'test:'.Ids::uuid7(), $this->w['t']['org'], $this->w['t']['site'], $items, holderName: $holder);
    }

    private function access(array $o = []): array
    {
        return $o + ['kind' => 'ACCESS', 'name' => 'Pool - Adult', 'qty' => 1, 'facilityUnitId' => $this->w['pool']->id, 'validationMode' => 'SINGLE_USE',
            'validFrom' => CarbonImmutable::now('UTC')->subHour(), 'validUntil' => CarbonImmutable::now('UTC')->addHours(5)];
    }

    private function redeem(string $token, ?string $facility = null, array $extra = [], ?string $authToken = null)
    {
        return $this->postJson("/api/v1/entitlement-tokens/{$token}/redeem", ['action' => 'ENTRY', 'facilityId' => $facility ?? $this->w['pool']->id] + $extra, $this->idem($authToken ?? $this->scan));
    }

    public function test_valid_then_used_and_every_scan_is_logged(): void
    {
        $e = $this->ticket([$this->access()]);
        $this->assertMatchesRegularExpression('/^R7\.[A-Za-z0-9_-]{24}\.[A-Za-z0-9_-]{12}$/', $e->qr_token);

        $this->redeem($e->qr_token)->assertOk()->assertJsonPath('result', 'VALID')->assertJsonPath('holderName', 'Ada Obi')->assertJsonPath('remaining', 0)
            ->assertJsonPath('itemName', 'Pool - Adult')->assertJson(fn ($j) => $j->whereType('redemptionId', 'string')->etc());
        $used = $this->redeem($e->qr_token)->assertOk()->assertJsonPath('result', 'USED')->assertJsonPath('redemptionId', null)->json();
        $this->assertNotNull($used['usedAt']);
        $this->assertStringStartsWith('Already used at', $used['message']);

        $this->assertSame(['VALID', 'USED'], DB::table('validation_event')->orderBy('created_at')->orderBy('id')->pluck('result')->all());
        $this->assertSame(1, DB::table('redemption')->where('action', 'ENTRY')->count());
        $this->assertSame('EXHAUSTED', DB::table('entitlement')->value('status'));
        $this->assertTrue(DB::table('outbox_event')->where('event_type', 'TicketRedeemed')->exists());
        $this->assertTrue(DB::table('audit_log')->where('action', 'ticket.redeem')->exists());
        $this->assertTrue(Audit::verifyChain()->valid);
    }

    public function test_all_six_scan_results(): void
    {
        $now = CarbonImmutable::now('UTC');
        $expired = $this->ticket([$this->access(['validFrom' => $now->subDays(2), 'validUntil' => $now->subDay()])]);
        $future = $this->ticket([$this->access(['validFrom' => $now->addHours(3), 'validUntil' => $now->addHours(4)])]);
        $cancelled = $this->ticket([$this->access()]);
        DB::table('entitlement')->where('id', Ids::toBinary($cancelled->id))->update(['status' => 'CANCELLED']);
        $ok = $this->ticket([$this->access()]);

        $this->redeem($expired->qr_token)->assertOk()->assertJsonPath('result', 'EXPIRED')->assertJsonPath('redemptionId', null);
        $nyv = $this->redeem($future->qr_token)->assertOk()->assertJsonPath('result', 'NOT_YET_VALID')->json();
        $this->assertNotNull($nyv['validFrom']);
        $this->redeem($cancelled->qr_token)->assertOk()->assertJsonPath('result', 'CANCELLED');
        $wrong = $this->redeem($ok->qr_token, $this->w['store']->id)->assertOk()->assertJsonPath('result', 'WRONG_FACILITY')->json();
        $this->assertSame($this->w['pool']->id, $wrong['expectedFacilityId']);
        $this->redeem($ok->qr_token)->assertOk()->assertJsonPath('result', 'VALID');
        $this->redeem($ok->qr_token)->assertOk()->assertJsonPath('result', 'USED');

        // nothing was consumed by the rejected scans
        $this->assertSame(0.0, (float) DB::table('entitlement_item')->where('entitlement_id', Ids::toBinary($expired->id))->value('qty_redeemed'));
        $this->assertSame(6, DB::table('validation_event')->count());
    }

    public function test_arena_ticket_is_valid_at_the_entrance_child_facility_but_not_at_the_pool(): void
    {
        $e = $this->ticket([$this->access(['facilityUnitId' => $this->w['arena']->id, 'name' => 'Court 1 - 10:00'])]);
        $this->redeem($e->qr_token, $this->w['pool']->id)->assertOk()->assertJsonPath('result', 'WRONG_FACILITY');
        $this->redeem($e->qr_token, $this->w['entrance']->id)->assertOk()->assertJsonPath('result', 'VALID');
    }

    public function test_booking_cancelled_makes_the_ticket_cancelled(): void
    {
        $r = $this->resource($this->w);
        [, $desk] = $this->staffWith($this->w, 'desk', self::BOOKING_PERMS);
        $slot = $this->slot();
        $held = $this->postJson('/api/v1/bookings/hold', ['resourceId' => $r->id, 'start' => $slot[0], 'end' => $slot[1]], $this->idem($desk))->json();
        $conf = $this->postJson("/api/v1/bookings/{$held['id']}/confirm", ['tenders' => [['tenderType' => 'CASH', 'amount' => '5000.0000']]], $this->idem($desk, ['If-Match' => '"v'.$held['rowVersion'].'"']))->assertOk()->json();
        $ent = Entitlement::query()->find($conf['entitlementId']);
        // early entry window not open yet at 2 days ahead
        $this->redeem($ent->qr_token, $this->w['entrance']->id)->assertOk()->assertJsonPath('result', 'NOT_YET_VALID');
        DB::table('booking')->where('id', Ids::toBinary($conf['id']))->update(['status' => 'CANCELLED']); // parent cancelled out-of-band
        $this->redeem($ent->qr_token, $this->w['entrance']->id)->assertOk()->assertJsonPath('result', 'CANCELLED');
    }

    public function test_unknown_malformed_and_tampered_tokens_are_404_ticket_invalid(): void
    {
        $e = $this->ticket([$this->access()]);
        $tampered = substr($e->qr_token, 0, -1).($e->qr_token[-1] === 'A' ? 'B' : 'A');
        foreach (['nonsense', 'R7.'.str_repeat('a', 24).'.'.str_repeat('b', 12), $tampered] as $bad) {
            $this->redeem($bad)->assertStatus(404)->assertJsonPath('code', 'ticket_invalid');
            $this->getJson("/api/v1/entitlement-tokens/{$bad}", $this->idem($this->scan))->assertStatus(404)->assertJsonPath('code', 'ticket_invalid');
        }
        $this->assertSame(0, DB::table('validation_event')->count());
    }

    public function test_tokens_are_random_and_unique(): void
    {
        $tokens = [];
        for ($i = 0; $i < 30; $i++) {
            $tokens[] = $this->ticket([$this->access()])->qr_token;
        }
        $this->assertCount(30, array_unique($tokens));
    }

    public function test_three_children_and_two_adults_individual_tickets_are_five_entitlements_and_issue_is_idempotent(): void
    {
        DB::table('ticket_type')->insert([
            ['id' => Ids::toBinary($adult = Ids::uuid7()), 'organization_id' => Ids::toBinary($this->w['t']['org']), 'site_id' => Ids::toBinary($this->w['t']['site']), 'facility_unit_id' => Ids::toBinary($this->w['pool']->id), 'product_id' => Ids::toBinary($pa = Ids::uuid7()), 'code' => 'POOL-ADULT', 'name' => 'Adult', 'format' => 'INDIVIDUAL', 'validation_mode' => 'SINGLE_USE'],
            ['id' => Ids::toBinary(Ids::uuid7()), 'organization_id' => Ids::toBinary($this->w['t']['org']), 'site_id' => Ids::toBinary($this->w['t']['site']), 'facility_unit_id' => Ids::toBinary($this->w['pool']->id), 'product_id' => Ids::toBinary($pc = Ids::uuid7()), 'code' => 'POOL-CHILD', 'name' => 'Child', 'format' => 'INDIVIDUAL', 'validation_mode' => 'SINGLE_USE'],
        ]);
        $lines = [
            ['lineId' => Ids::uuid7(), 'productId' => $pc, 'name' => 'Pool - Child', 'kind' => 'TICKET', 'quantity' => 3, 'facilityId' => null],
            ['lineId' => Ids::uuid7(), 'productId' => $pa, 'name' => 'Pool - Adult', 'kind' => 'TICKET', 'quantity' => 2, 'facilityId' => null],
            ['lineId' => Ids::uuid7(), 'productId' => Ids::uuid7(), 'name' => 'Racket', 'kind' => 'RENTAL', 'quantity' => 2, 'facilityId' => $this->w['store']->id],
            ['lineId' => Ids::uuid7(), 'productId' => Ids::uuid7(), 'name' => 'Cola', 'kind' => 'GOOD', 'quantity' => 1, 'facilityId' => null],
        ];
        $orderId = Ids::uuid7();
        $svc = app(EntitlementService::class);
        $first = $svc->issueForOrderLines($orderId, $this->w['t']['org'], $this->w['t']['site'], $lines, 'Family Obi');
        $this->assertCount(6, $first); // 5 tickets + 1 rentals entitlement (goods are not entitlements)
        $tickets = collect($first)->filter(fn ($e) => $e->items[0]->kind === 'ACCESS');
        $this->assertCount(5, $tickets);
        $tickets->each(fn ($e) => $this->assertCount(1, $e->items) && $this->assertEquals(1, $e->items[0]->qty));
        $this->assertCount(5, $tickets->pluck('qr_token')->unique());

        $again = $svc->issueForOrderLines($orderId, $this->w['t']['org'], $this->w['t']['site'], $lines, 'Family Obi');
        $this->assertEqualsCanonicalizing(collect($first)->pluck('id')->all(), collect($again)->pluck('id')->all());
        $this->assertSame(6, Entitlement::query()->where('order_id', $orderId)->count());
    }

    public function test_combined_ticket_is_one_entitlement_with_multiple_entry(): void
    {
        DB::table('ticket_type')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'organization_id' => Ids::toBinary($this->w['t']['org']), 'site_id' => Ids::toBinary($this->w['t']['site']), 'facility_unit_id' => Ids::toBinary($this->w['pool']->id), 'product_id' => Ids::toBinary($p = Ids::uuid7()), 'code' => 'POOL-GROUP', 'name' => 'Group', 'format' => 'COMBINED', 'validation_mode' => 'MULTIPLE_ENTRY']);
        $out = app(EntitlementService::class)->issueForOrderLines(Ids::uuid7(), $this->w['t']['org'], $this->w['t']['site'], [['lineId' => Ids::uuid7(), 'productId' => $p, 'name' => 'Pool group', 'kind' => 'TICKET', 'quantity' => 5, 'facilityId' => null]], 'Group');
        $this->assertCount(1, $out);
        $this->assertEquals(5, $out[0]->items[0]->qty);
        $this->assertSame('MULTIPLE_ENTRY', $out[0]->items[0]->validation_mode);
        for ($i = 1; $i <= 5; $i++) {
            $this->redeem($out[0]->qr_token)->assertJsonPath('result', 'VALID')->assertJsonPath('remaining', 5 - $i);
        }
        $this->redeem($out[0]->qr_token)->assertJsonPath('result', 'USED');
    }

    public function test_validation_modes(): void
    {
        // NONE: informational, never consumed
        $none = $this->ticket([$this->access(['validationMode' => 'NONE'])]);
        $this->redeem($none->qr_token)->assertJsonPath('result', 'VALID');
        $this->redeem($none->qr_token)->assertJsonPath('result', 'VALID');
        $this->assertSame(0, DB::table('redemption')->count());

        // SINGLE_USE group ticket: one scan admits the whole group
        $group = $this->ticket([$this->access(['qty' => 4])]);
        $this->redeem($group->qr_token)->assertJsonPath('result', 'VALID')->assertJsonPath('remaining', 0);
        $this->redeem($group->qr_token)->assertJsonPath('result', 'USED');

        // MULTIPLE_ENTRY with an explicit quantity per scan, cannot overdraw
        $multi = $this->ticket([$this->access(['qty' => 10, 'validationMode' => 'MULTIPLE_ENTRY'])]);
        $this->redeem($multi->qr_token, null, ['quantity' => 4])->assertJsonPath('remaining', 6);
        $this->redeem($multi->qr_token, null, ['quantity' => 6])->assertJsonPath('result', 'VALID')->assertJsonPath('remaining', 0);
        $this->redeem($multi->qr_token, null, ['quantity' => 1])->assertJsonPath('result', 'USED');
        $over = $this->ticket([$this->access(['qty' => 3, 'validationMode' => 'MULTIPLE_ENTRY'])]);
        $this->redeem($over->qr_token, null, ['quantity' => 4])->assertJsonPath('result', 'USED'); // 4 > 3: refused atomically
        $this->assertSame(0.0, (float) DB::table('entitlement_item')->where('entitlement_id', Ids::toBinary($over->id))->value('qty_redeemed'));

        // TIME_LIMITED needs a window
        $tl = $this->ticket([$this->access(['validationMode' => 'TIME_LIMITED', 'validFrom' => null, 'validUntil' => null])]);
        $this->redeem($tl->qr_token)->assertJsonPath('result', 'EXPIRED');
        $tl2 = $this->ticket([$this->access(['validationMode' => 'TIME_LIMITED', 'qty' => 2])]);
        $this->redeem($tl2->qr_token)->assertJsonPath('result', 'VALID');
    }

    public function test_entry_exit_tracks_headcount_and_exit_needs_an_open_entry(): void
    {
        $e = $this->ticket([$this->access(['validationMode' => 'ENTRY_EXIT', 'qty' => 2])]);
        $exit = fn () => $this->postJson("/api/v1/entitlement-tokens/{$e->qr_token}/exit", ['facilityId' => $this->w['pool']->id], $this->idem($this->scan));

        $exit()->assertStatus(409)->assertJsonPath('code', 'ticket_invalid'); // never entered
        $this->redeem($e->qr_token)->assertJsonPath('result', 'VALID');
        $this->assertEquals(1, DB::table('entitlement_item')->value('qty_inside'));
        $exit()->assertOk()->assertJsonPath('result', 'VALID')->assertJsonPath('message', 'Exit recorded');
        $this->assertEquals(0, DB::table('entitlement_item')->value('qty_inside'));
        $exit()->assertStatus(409);
        $this->redeem($e->qr_token)->assertJsonPath('result', 'VALID'); // second entry allowed (qty 2)
        $this->redeem($e->qr_token)->assertJsonPath('result', 'USED');
        $this->assertSame(['ENTRY', 'ENTRY', 'EXIT'], DB::table('redemption')->pluck('action')->sort()->values()->all());
    }

    public function test_staff_approval_needs_an_override_by_someone_holding_the_override_permission(): void
    {
        $e = $this->ticket([$this->access(['validationMode' => 'STAFF_APPROVAL'])]);
        [, $plain] = $this->staffWith($this->w, 'plain', ['ticket.view', 'ticket.redeem']);

        $this->redeem($e->qr_token, null, [], $plain)->assertStatus(409)->assertJsonPath('code', 'approval_required');
        $this->redeem($e->qr_token, null, ['override' => true], $plain)->assertStatus(409)->assertJsonPath('code', 'approval_required'); // asked, but lacks the permission
        $this->assertSame(0, DB::table('redemption')->count());
        $this->redeem($e->qr_token, null, ['override' => true])->assertOk()->assertJsonPath('result', 'VALID');
        $this->assertTrue(DB::table('audit_log')->where('action', 'ticket.override')->exists());
    }

    public function test_redeem_is_facility_scoped_by_permission_and_needs_a_facility(): void
    {
        $e = $this->ticket([$this->access()]);
        $staff = TestData::staff($this->w['t'], 'poolguy');
        TestData::assignRole($staff, TestData::customRole('POOL_ONLY', 'Pool only', ['ticket.view', 'ticket.redeem']), 'FACILITY_UNIT', $this->w['pool']->id);
        $tok = $this->postJson('/api/v1/auth/staff/login', ['username' => 'poolguy', 'password' => TestData::PASSWORD])->json('accessToken');

        $this->redeem($e->qr_token, $this->w['store']->id, [], $tok)->assertStatus(403)->assertJsonPath('code', 'permission_denied');
        $this->postJson("/api/v1/entitlement-tokens/{$e->qr_token}/redeem", ['action' => 'ENTRY'], $this->idem($tok))->assertStatus(422); // no facilityId, no device
        $this->redeem($e->qr_token, $this->w['pool']->id, [], $tok)->assertJsonPath('result', 'VALID');
    }

    public function test_redeem_replay_with_same_idempotency_key_returns_the_original_result(): void
    {
        $e = $this->ticket([$this->access()]);
        $h = $this->idem($this->scan);
        $a = $this->postJson("/api/v1/entitlement-tokens/{$e->qr_token}/redeem", ['action' => 'ENTRY', 'facilityId' => $this->w['pool']->id], $h)->assertOk()->assertJsonPath('result', 'VALID')->json();
        $b = $this->postJson("/api/v1/entitlement-tokens/{$e->qr_token}/redeem", ['action' => 'ENTRY', 'facilityId' => $this->w['pool']->id], $h)->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('result', 'VALID')->json();
        $this->assertSame($a['redemptionId'], $b['redemptionId']);
        $this->assertSame(1, DB::table('redemption')->count());
        $this->redeem($e->qr_token)->assertJsonPath('result', 'USED'); // a fresh scan is genuinely used
    }

    public function test_rental_release_and_return_lifecycle_and_duplicates_are_impossible(): void
    {
        $hook = new class implements RentalStockHook
        {
            public array $out = [];

            public array $in = [];

            public function rentalOut(array $c): void
            {
                $this->out[] = $c;
            }

            public function rentalIn(array $c): void
            {
                $this->in[] = $c;
            }
        };
        $this->app->instance(RentalStockHook::class, $hook);

        $e = $this->ticket([
            $this->access(['facilityUnitId' => $this->w['arena']->id]),
            ['kind' => 'RENTAL', 'name' => 'Racket x2', 'qty' => 2, 'facilityUnitId' => $this->w['store']->id, 'productId' => Ids::uuid7()],
            ['kind' => 'RENTAL', 'name' => 'Balls', 'qty' => 1, 'facilityUnitId' => $this->w['store']->id],
            ['kind' => 'GOODS', 'name' => 'Water', 'qty' => 1, 'facilityUnitId' => $this->w['store']->id],
        ]);
        [$access, $racket, $balls, $water] = $e->items->all();
        $this->assertSame('NOT_RELEASED', $racket->rentalStatus());

        $lookup = $this->getJson("/api/v1/entitlement-tokens/{$e->qr_token}", $this->idem($this->scan))->assertOk()->json();
        $this->assertSame('NOT_RELEASED', $lookup['items'][1]['rentalStatus']);
        $this->assertSame('ITEM', $lookup['items'][3]['kind']);
        $this->assertSame('GOODS', $lookup['items'][3]['subKind']);

        $rel = fn (array $ids, ?string $tok = null) => $this->postJson("/api/v1/entitlements/{$e->id}/release", ['itemIds' => $ids, 'note' => '2 rackets', 'depositCollected' => '1000.0000'], $this->idem($tok ?? $this->scan));
        $ret = fn (array $ids, string $cond = 'OK', ?string $charge = null) => $this->postJson("/api/v1/entitlements/{$e->id}/return", ['itemIds' => $ids, 'condition' => $cond] + ($charge ? ['damageCharge' => $charge] : []), $this->idem($this->scan));

        $ret([$racket->id])->assertStatus(409)->assertJsonPath('code', 'ticket_invalid'); // not released yet
        $this->postJson("/api/v1/entitlements/{$e->id}/release", ['itemIds' => [$access->id]], $this->idem($this->scan))->assertStatus(422); // ACCESS is not a rental
        $rel([$racket->id])->assertOk()->assertJsonPath('items.1.rentalStatus', 'RELEASED')->assertJsonPath('items.1.quantityRedeemed', 2);
        $rel([$racket->id])->assertStatus(409)->assertJsonPath('code', 'ticket_used'); // duplicate release
        $rel([$balls->id, $racket->id])->assertStatus(409); // all-or-nothing: balls stay NOT_RELEASED
        $this->assertSame('NOT_RELEASED', $this->getJson("/api/v1/entitlements/{$e->id}", $this->idem($this->scan))->json('items.2.rentalStatus'));
        $this->assertCount(1, $hook->out);
        $this->assertSame('2.000', $hook->out[0]['quantity']);

        $ret([$racket->id], 'DAMAGED', '2500.0000')->assertOk()->assertJsonPath('items.1.rentalStatus', 'RETURNED');
        $ret([$racket->id])->assertStatus(409)->assertJsonPath('code', 'ticket_used'); // duplicate return
        $this->assertSame('2500.0000', DB::table('redemption')->where('action', 'RETURN')->value('amount'));
        $this->assertSame('DAMAGED', DB::table('redemption')->where('action', 'RETURN')->value('item_condition'));
        $this->assertSame(1, DB::table('redemption')->where('action', 'RELEASE')->count());
        $this->assertCount(1, $hook->in);
        $this->assertSame('DAMAGED', $hook->in[0]['condition']);
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'RentalReleased')->count());
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'RentalReturned')->count());
        $this->assertTrue(Audit::verifyChain()->valid);
        $this->assertSame('ACTIVE', DB::table('entitlement')->value('status')); // access + others still unconsumed
    }

    public function test_facility_comes_from_the_device_checkout_binding_and_is_enforced(): void
    {
        [$staff] = $this->staffWith($this->w, 'devstaff', self::SCAN_PERMS);
        $deviceId = Ids::uuid7();
        DB::table('device')->insert(['id' => Ids::toBinary($deviceId), 'organization_id' => Ids::toBinary($this->w['t']['org']), 'site_id' => Ids::toBinary($this->w['t']['site']), 'device_type' => 'TABLET', 'name' => 'Store tablet']);
        DB::table('device_binding')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'device_id' => Ids::toBinary($deviceId), 'facility_unit_id' => Ids::toBinary($this->w['pool']->id)]);
        $req = Request::create('/x');
        $req->attributes->set(RequestContext::STAFF_ID, $staff->id);
        $req->attributes->set(RequestContext::ORGANIZATION_ID, $this->w['t']['org']);
        $req->attributes->set(RequestContext::SITE_ID, $this->w['t']['site']);
        $req->attributes->set(RequestContext::DEVICE_ID, $deviceId);
        $this->app->instance('request', $req);

        $e = $this->ticket([$this->access(), ['kind' => 'RENTAL', 'name' => 'Racket', 'qty' => 1, 'facilityUnitId' => $this->w['store']->id]]);
        $svc = app(RedemptionService::class);

        // the device is checked out at the POOL: ticket for the pool is VALID without passing facilityId; device id lands on the redemption
        $this->assertSame('VALID', $svc->redeem($e->qr_token)['result']);
        $this->assertSame($deviceId, Ids::fromBinary(DB::table('validation_event')->value('device_id')));
        $this->assertSame($deviceId, Ids::fromBinary(DB::table('redemption')->value('device_id')));
        // ...but a Sports Store rental cannot be released from a pool-bound device
        try {
            $svc->release($e->id, [$e->items[1]->id]);
            $this->fail('expected facility_mismatch');
        } catch (ApiProblem $p) {
            $this->assertSame('facility_mismatch', $p->problemCode);
        }
        $this->assertSame(0, DB::table('redemption')->where('action', 'RELEASE')->count());
    }

    public function test_issue_by_booking_is_idempotent_and_rejects_unconfirmed_bookings(): void
    {
        $r = $this->resource($this->w);
        [, $desk] = $this->staffWith($this->w, 'desk2', self::BOOKING_PERMS);
        $slot = $this->slot();
        $held = $this->postJson('/api/v1/bookings/hold', ['resourceId' => $r->id, 'start' => $slot[0], 'end' => $slot[1]], $this->idem($desk))->json();
        $this->postJson('/api/v1/entitlements', ['bookingId' => $held['id']], $this->idem($desk))->assertStatus(409)->assertJsonPath('code', 'booking_state_invalid');
        $conf = $this->postJson("/api/v1/bookings/{$held['id']}/confirm", ['tenders' => [['tenderType' => 'CASH', 'amount' => '5000.0000']]], $this->idem($desk, ['If-Match' => '"v'.$held['rowVersion'].'"']))->json();
        $re = $this->postJson('/api/v1/entitlements', ['bookingId' => $held['id']], $this->idem($desk))->assertStatus(201)->json();
        $this->assertSame($conf['entitlementId'], $re['id']);
        $this->assertSame(1, DB::table('entitlement')->count());
        $this->postJson('/api/v1/entitlements', ['orderId' => Ids::uuid7()], $this->idem($desk))->assertStatus(404); // unknown order
        $list = $this->getJson("/api/v1/entitlements?filter[bookingId]={$held['id']}", $this->idem($desk))->assertOk()->json();
        $this->assertCount(1, $list['items']);
    }
}
