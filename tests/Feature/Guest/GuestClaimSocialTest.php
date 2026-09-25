<?php

namespace Tests\Feature\Guest;

use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\CustomerHelpers;
use Tests\Support\DemoApi;
use Tests\Support\PaystackFakes;
use Tests\Support\SocialHelpers;
use Tests\TestCase;

/** Guest orders are claimed when a social sign-in / link / email change makes an email VERIFIED, and never for unverified emails. */
class GuestClaimSocialTest extends TestCase
{
    use CustomerHelpers, DemoApi, SocialHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        $this->enableSocial();
        PaystackFakes::configure();
        PaystackFakes::http();
    }

    /** @return array<string, mixed> a guest booking hold (unpaid is enough to be claimable) */
    private function guestHold(string $email, int $hour = 10): array
    {
        $s = CarbonImmutable::now('Africa/Lagos')->addDays(3)->setTime($hour, 0)->utc();
        $res = Ids::fromBinary($this->resourceByName('Lawn Tennis Court 1')->id);

        return $this->postJson('/api/v1/bookings/hold', ['resourceId' => $res, 'start' => $s->format('Y-m-d\TH:i:s\Z'), 'end' => $s->addHour()->format('Y-m-d\TH:i:s\Z'),
            'guest' => ['name' => 'Ada Guest', 'email' => $email, 'phone' => '08031234567', 'consentVersion' => 'v1']],
            ['Authorization' => 'Bearer r7s_dev_booking_web', 'Accept' => 'application/json', 'Idempotency-Key' => 'k-'.bin2hex(random_bytes(8))])->assertStatus(201)->json();
    }

    public function test_social_login_with_a_verified_email_claims_earlier_guest_orders(): void
    {
        $b = $this->guestHold('ada.social@example.test');
        $other = $this->guestHold('someone.else@example.test', 12);
        $r = $this->socialLogin($this->claims(['email' => 'Ada.Social@Example.test']))->assertStatus(201);
        $cid = $r->json('customer.id');
        $this->assertSame($cid, Ids::fromBinary(DB::table('booking')->where('id', Ids::toBinary($b['id']))->value('customer_id')));
        $this->assertNull(DB::table('booking')->where('id', Ids::toBinary($other['id']))->value('customer_id'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'guest.order.claim')->count());
        $mine = $this->getJson('/api/v1/customer/bookings', $this->bearer($r->json('accessToken'), null))->assertOk()->json('items');
        $this->assertSame([$b['id']], array_column($mine, 'id'));
    }

    public function test_social_login_with_an_unverified_email_claims_nothing(): void
    {
        $b = $this->guestHold('unverified.social@example.test');
        $this->socialLogin($this->claims(['provider' => 'facebook', 'email' => 'unverified.social@example.test', 'emailVerified' => false]))->assertStatus(201);
        $this->assertNull(DB::table('booking')->where('id', Ids::toBinary($b['id']))->value('customer_id'));
        $this->assertSame(0, DB::table('guest_order')->whereNotNull('claimed_customer_id')->count());
    }

    public function test_adding_an_email_by_code_claims_only_after_that_verification(): void
    {
        $b = $this->guestHold('later.added@example.test');
        $r = $this->socialLogin(['provider' => 'facebook', 'providerUserId' => 'fb-late', 'emailVerified' => false, 'name' => 'Late Added', 'termsAccepted' => true])->assertStatus(201);
        $h = $this->bearer($r->json('accessToken'), null);
        $this->postJson('/api/v1/customer/me/email', ['email' => 'later.added@example.test'], $h)->assertStatus(202);
        $this->assertNull(DB::table('booking')->where('id', Ids::toBinary($b['id']))->value('customer_id'), 'requesting the email is not verifying it');
        $this->postJson('/api/v1/customer/me/email/verify', ['code' => $this->lastMailCode()], $h)->assertOk();
        $this->assertSame($r->json('customer.id'), Ids::fromBinary(DB::table('booking')->where('id', Ids::toBinary($b['id']))->value('customer_id')));
    }

    public function test_linking_a_verified_social_identity_to_a_password_account_claims_after_confirmation(): void
    {
        $email = 'linked.acct@example.test';
        $b = $this->guestHold($email);
        $this->postJson('/api/v1/customer/auth/register', ['name' => 'Linked', 'email' => $email, 'phone' => '+23480'.random_int(10000000, 99999999), 'password' => self::PW])->assertStatus(201);
        $this->assertNull(DB::table('booking')->where('id', Ids::toBinary($b['id']))->value('customer_id'));
        // provider-verified email adopts/links the unverified account: the mailbox is proven only via the confirmation code
        $r = $this->socialLogin($this->claims(['email' => $email]));
        if ($r->status() === 409) {
            $this->postJson('/api/v1/public/customers/social/link/confirm', ['email' => $email, 'code' => $this->lastMailCode()], $this->svc())->assertOk();
        }
        $this->assertNotNull(DB::table('booking')->where('id', Ids::toBinary($b['id']))->value('customer_id'));
    }
}
