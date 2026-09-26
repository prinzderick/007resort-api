<?php

namespace App\Domain\Guest\Http\Controllers;

use App\Domain\Customer\Services\CustomerAuthService;
use App\Domain\Customer\Support\Actor;
use App\Domain\Guest\Services\GuestAccess;
use App\Domain\Guest\Services\GuestCheckout;
use App\Domain\Guest\Services\GuestMessenger;
use App\Domain\Guest\Services\GuestOrderView;
use App\Domain\Guest\Support\ContactNormalizer;
use App\Domain\Guest\Support\GuestSession;
use App\Domain\Guest\Support\Limits;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Guest order endpoints (docs/GUEST_CHECKOUT.md s4.3, s6). Service token only; the order is identified by X-Order-Token or by reference + contact. */
class GuestOrderController
{
    public function __construct(private readonly GuestOrderView $view, private readonly GuestCheckout $checkout, private readonly GuestAccess $access) {}

    public function show(string $reference): JsonResponse
    {
        $g = $this->session($reference);

        return response()->json($this->view->present($this->row($g)));
    }

    public function lookup(Request $request): JsonResponse
    {
        $this->checkout->assertEnabled();
        $d = $request->validate([
            'reference' => ['required', 'string', 'max:32'],
            'email' => ['required_without:phone', 'nullable', 'string', 'max:254'],
            'phone' => ['required_without:email', 'nullable', 'string', 'max:40'],
        ]);
        $ref = strtoupper(trim($d['reference']));
        $email = isset($d['email']) ? ContactNormalizer::email($d['email']) : null;
        $phone = isset($d['phone']) ? ContactNormalizer::phone($d['phone']) : null;
        $contact = $email ?? $phone ?? '';
        $r = config('guest.rate');
        Limits::hit('lookup-ip', GuestCheckout::clientIp($request), $r['lookup_per_ip_15min'], 900);
        Limits::hit('lookup-ref', $ref, $r['lookup_per_reference_15min'], 900);
        Limits::hit('lookup-contact', $contact, $r['lookup_per_contact_15min'], 900);

        $row = DB::table('guest_order')->where('reference', $ref)->whereNull('erased_at')->first();
        $known = $row === null ? '' : (string) ($email !== null ? $row->contact_email : $row->contact_phone);
        // constant-time compare against a dummy when the reference is unknown; ONE generic failure for every reason
        $match = hash_equals($known !== '' ? $known : 'x', $contact !== '' ? $contact : 'y') && $row !== null && $known !== '' && $contact !== '';
        if (! $match) {
            Audit::securityEvent('guest.order.lookup_failed', 'INFO', null, GuestCheckout::clientIp($request), ['ref' => ContactNormalizer::fingerprint($ref)]);
            throw ApiProblem::notFound('order_not_found', "We couldn't find an order with those details.");
        }
        $tok = $this->access->mint(Ids::fromBinary($row->id));
        Audit::record('guest.order.lookup', 'GuestOrder', Ids::fromBinary($row->id), null, ['reference' => $ref], organizationId: Ids::fromBinary($row->organization_id));

        return response()->json($this->view->present($row) + ['guestAccess' => [
            'reference' => $row->reference, 'accessToken' => $tok['token'], 'accessTokenExpiresAt' => $tok['expiresAt'],
            'contact' => ['name' => $row->contact_name, 'email' => $row->contact_email, 'phone' => $row->contact_phone],
        ]]);
    }

    public function resend(Request $request, string $reference, GuestMessenger $messenger): JsonResponse
    {
        $g = $this->session($reference);
        $d = $request->validate(['channel' => ['required', 'in:EMAIL,SMS']]);
        $row = $this->row($g);
        $v = $this->view->present($row);
        if (! in_array($v['status'], ['CONFIRMED', 'RESCHEDULED', 'COMPLETED', 'PAID', 'ACTIVE'], true)) {
            throw ApiProblem::conflict('nothing_to_send', 'There is nothing to send yet: the order is not paid.');
        }
        Limits::hit('resend', $g->reference, (int) config('guest.rate.resend_per_order_hour'), 3600);
        $messenger->queueResend($row->id, $d['channel']);

        return response()->json(['queued' => true, 'channel' => $d['channel']], 202);
    }

    public function createAccount(Request $request, string $reference, CustomerAuthService $auth): JsonResponse
    {
        $g = $this->session($reference);
        $d = $request->validate([
            'password' => ['required', 'string', 'min:'.config('customer.password_min_length'), 'max:128'],
            'termsAccepted' => ['required', 'accepted'],
        ]);
        Limits::hit('create-account', $g->reference, 5, 3600);
        // Normal registration for the order's email: unverified until the mailbox owner verifies; only THEN does the claimer attach the order.
        $auth->register(['name' => (string) $g->contactName, 'email' => (string) $g->contactEmail, 'phone' => $g->contactPhone, 'password' => $d['password']], GuestCheckout::clientIp($request));

        return response()->json(['verificationRequired' => true], 202); // generic: never reveals whether an account already existed
    }

    private function session(string $reference): GuestSession
    {
        $this->checkout->assertEnabled();
        $g = Actor::guest() ?? throw ApiProblem::unauthenticated('order_token_invalid', 'An X-Order-Token is required.');
        if ($g->reference !== strtoupper(trim($reference))) {
            throw ApiProblem::notFound('not_found', 'Order not found.');
        }

        return $g;
    }

    private function row(GuestSession $g): object
    {
        return DB::table('guest_order')->where('id', Ids::toBinary($g->id))->first() ?? throw ApiProblem::notFound('not_found', 'Order not found.');
    }
}
