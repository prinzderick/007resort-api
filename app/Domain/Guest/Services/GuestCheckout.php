<?php

namespace App\Domain\Guest\Services;

use App\Domain\Customer\Support\Actor;
use App\Domain\Guest\Support\ContactNormalizer;
use App\Domain\Guest\Support\GuestContact;
use App\Domain\Guest\Support\Limits;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Entry point of every guest purchase: validate + normalise the `guest` object, apply abuse limits and CAPTCHA, then (inside the purchase
 * transaction) open a guest order + access token. NO customer / account row is created here or anywhere in guest checkout.
 */
class GuestCheckout
{
    private const FMT = 'Y-m-d H:i:s.u';

    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // Crockford base32

    public function __construct(private readonly Turnstile $turnstile, private readonly GuestAccess $access) {}

    /** True when this request must be treated as a guest checkout (service token with checkout scope, no customer). */
    public function isGuestRequest(): bool
    {
        return ! Actor::isStaff() && ! Actor::isCustomer() && Actor::isService();
    }

    /** @throws ApiProblem */
    public function assertEnabled(): void
    {
        if (! config('guest.enabled')) {
            throw ApiProblem::forbidden('guest_checkout_disabled', 'Checkout without an account is not available right now.');
        }
        if (! Actor::serviceCan('public.checkout')) {
            throw ApiProblem::forbidden('scope_denied', 'The service token lacks the public.checkout scope.');
        }
    }

    /**
     * Guest checkout requires the `guest` object; customers must not send it.
     *
     * @param  array<string, mixed>|null  $input
     */
    public function contact(?array $input, Request $request): GuestContact
    {
        $this->assertEnabled();
        if ($input === null) {
            throw ApiProblem::unprocessable('guest_required', 'Provide the guest name, email and phone to check out without an account.', ['guest' => ['required']]);
        }
        $intl = (bool) config('guest.allow_international_phones');
        $v = Validator::make(['guest' => $input], [
            'guest' => ['required', 'array'],
            'guest.name' => ['required', 'string', fn ($a, $val, $fail) => ContactNormalizer::name((string) $val) === null ? $fail('The name must be 2 to 120 characters.') : null],
            'guest.email' => ['required', 'string', fn ($a, $val, $fail) => ContactNormalizer::email((string) $val) === null ? $fail('Enter a valid email address.') : null],
            'guest.phone' => ['required', 'string', fn ($a, $val, $fail) => ContactNormalizer::phone((string) $val, $intl) === null ? $fail('Enter a valid phone number, e.g. 0803 123 4567.') : null],
            'guest.marketingConsent' => ['nullable', 'boolean'],
            'guest.consentVersion' => ['required', 'string', 'min:1', 'max:32'],
            'guest.captchaToken' => ['nullable', 'string', 'max:2048'],
        ]);
        $v->validate();

        $ip = self::clientIp($request);
        $this->turnstile->assertHuman($input['captchaToken'] ?? null, $ip);
        $c = new GuestContact(
            ContactNormalizer::name($input['name']), ContactNormalizer::email($input['email']), ContactNormalizer::phone($input['phone'], $intl),
            filter_var($input['marketingConsent'] ?? false, FILTER_VALIDATE_BOOL), (string) $input['consentVersion'], $ip,
        );
        $r = config('guest.rate');
        Limits::hit('create-ip', (string) $ip, $r['create_per_ip_hour'], 3600);
        Limits::hit('create-email', $c->email, $r['create_per_email_hour'], 3600);
        Limits::hit('create-phone', $c->phone, $r['create_per_phone_hour'], 3600);

        return $c;
    }

    /**
     * Create the guest order for a purchase that already exists (same transaction). Serialised per contact email so the active-hold cap is race-free.
     *
     * @param  'BOOKING'|'TICKETS'|'MEMBERSHIP'  $kind
     * @return array{reference: string, accessToken: string, accessTokenExpiresAt: string, contact: array{name: string, email: string, phone: string}}
     */
    public function open(GuestContact $c, string $kind, string $subjectId, ?string $organizationId = null): array
    {
        $org = $organizationId ?? Tenant::organizationId() ?? throw ApiProblem::badRequest('tenant_unresolved', 'No organization in context.');
        $orgBin = Ids::toBinary($org);
        $now = CarbonImmutable::now('UTC');
        DB::statement('INSERT INTO guest_contact (id, organization_id, email, phone, name, marketing_consent, consent_version, consented_at, order_count, first_seen_at, last_seen_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
            ON DUPLICATE KEY UPDATE phone = VALUES(phone), name = VALUES(name), marketing_consent = VALUES(marketing_consent), consent_version = VALUES(consent_version),
              consented_at = VALUES(consented_at), order_count = order_count + 1, last_seen_at = VALUES(last_seen_at)',
            [Ids::toBinary(Ids::uuid7()), $orgBin, $c->email, $c->phone, $c->name, $c->marketingConsent ? 1 : 0, $c->consentVersion, $now->format(self::FMT), $now->format(self::FMT), $now->format(self::FMT)]);
        $contactId = DB::selectOne('SELECT id FROM guest_contact WHERE organization_id = ? AND email = ? FOR UPDATE', [$orgBin, $c->email])->id;

        $id = Ids::uuid7();
        $subject = ['BOOKING' => 'booking_id', 'TICKETS' => 'order_id', 'MEMBERSHIP' => 'membership_id'][$kind];
        $reference = $this->reference();
        DB::table('guest_order')->insert([
            'id' => Ids::toBinary($id), 'organization_id' => $orgBin, 'reference' => $reference, 'kind' => $kind, $subject => Ids::toBinary($subjectId),
            'guest_contact_id' => $contactId, 'contact_name' => $c->name, 'contact_email' => $c->email, 'contact_phone' => $c->phone,
            'consent_version' => $c->consentVersion, 'consented_at' => $now->format(self::FMT), 'marketing_consent' => $c->marketingConsent ? 1 : 0,
            'client_ip_hash' => $c->clientIp === null ? null : ContactNormalizer::fingerprint($c->clientIp),
        ]);
        if ($kind === 'BOOKING') {
            $live = (int) DB::table('booking as b')->join('guest_order as g', 'g.booking_id', '=', 'b.id')
                ->whereIn('b.status', ['HELD', 'PENDING_PAYMENT'])->where('b.hold_expires_at', '>', $now->format(self::FMT))->whereNull('g.erased_at')
                ->where(fn ($w) => $w->where('g.contact_email', $c->email)->orWhere('g.contact_phone', $c->phone))->count();
            if ($live > (int) config('guest.max_active_holds')) {
                throw ApiProblem::conflict('too_many_active_holds', 'You already have the maximum number of unpaid holds. Pay for one, or wait for it to expire, then try again.');
            }
        }
        $this->setSubjectContact($kind, $subjectId, $c);
        $tok = $this->access->mint($id);
        Audit::record('guest.order.create', 'GuestOrder', $id, null, [
            'reference' => $reference, 'kind' => $kind, 'subjectId' => $subjectId, 'contact' => ContactNormalizer::fingerprint($c->email), 'consentVersion' => $c->consentVersion,
            'marketingConsent' => $c->marketingConsent,
        ], organizationId: $org);

        return ['reference' => $reference, 'accessToken' => $tok['token'], 'accessTokenExpiresAt' => $tok['expiresAt'], 'contact' => $c->snapshot()];
    }

    /** Snapshot the contact on the purchase row(s) that have no snapshot columns of their own yet. */
    private function setSubjectContact(string $kind, string $subjectId, GuestContact $c): void
    {
        $bin = Ids::toBinary($subjectId);
        $snap = ['contact_name' => $c->name, 'contact_email' => $c->email, 'contact_phone' => $c->phone];
        if ($kind === 'TICKETS') {
            DB::table('customer_order')->where('order_id', $bin)->update($snap);
        } elseif ($kind === 'MEMBERSHIP') {
            DB::table('membership')->where('id', $bin)->update($snap);
        }
    }

    private function reference(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $s = '';
            foreach (str_split(random_bytes(8)) as $ch) {
                $s .= self::ALPHABET[ord($ch) & 31];
            }
            $ref = 'GC-'.$s;
            if (! DB::table('guest_order')->where('reference', $ref)->exists()) {
                return $ref;
            }
        }
        throw new \RuntimeException('Could not allocate a guest reference.');
    }

    /** The visitor's IP: the website BFF forwards it in X-Client-IP (trusted for service tokens only). */
    public static function clientIp(Request $request): string
    {
        $fwd = (string) $request->header('X-Client-IP');
        if (Actor::isService() && filter_var($fwd, FILTER_VALIDATE_IP) !== false) {
            return $fwd;
        }

        return (string) $request->ip();
    }
}
