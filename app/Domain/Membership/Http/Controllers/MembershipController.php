<?php

namespace App\Domain\Membership\Http\Controllers;

use App\Domain\Customer\Support\Actor;
use App\Domain\Customer\Support\Owns;
use App\Domain\Identity\Auth\Scope;
use App\Domain\Identity\Services\PermissionChecker;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\MembershipUsage;
use App\Domain\Membership\Services\MembershipLifecycle;
use App\Domain\Membership\Services\MembershipService;
use App\Domain\Membership\Services\MembershipValidator;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Http\Paged;
use App\Support\RequestContext;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MembershipController
{
    public function __construct(
        private readonly MembershipService $memberships,
        private readonly MembershipValidator $validator,
        private readonly MembershipLifecycle $lifecycle,
        private readonly PermissionChecker $checker,
    ) {}

    /** POST /memberships  (permission membership.sell; Idempotency-Key) */
    public function purchase(Request $request): JsonResponse
    {
        $d = $request->validate([
            'planId' => ['required', 'uuid'],
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'max:200'],
            'customer.phone' => ['nullable', 'string', 'max:32'],
            'customer.email' => ['nullable', 'email', 'max:255'],
            'facilityId' => ['sometimes', 'nullable', 'uuid'],
            'paystackReference' => ['sometimes', 'nullable', 'string', 'max:128'],
            ...$this->tenderRules(),
        ]);
        if (! empty($d['facilityId'])) {
            $this->requireAt('membership.sell', $d['facilityId']);
        }
        if (Actor::isCustomer()) { // online: the holder is the signed-in customer; no tenders (Paystack captures and activates)
            $m = $this->memberships->purchase($d['planId'], $d['customer'], null, [], null, null, 'ONLINE', Actor::customerId());

            return response()->json($m->toApi(withCards: true), 201);
        }
        $m = $this->memberships->purchase($d['planId'], $d['customer'], $d['facilityId'] ?? null, $d['tenders'] ?? [], $d['paystackReference'] ?? null, RequestContext::staffId());

        return response()->json($m->toApi(withCards: true), 201);
    }

    /** GET /memberships?q=&filter[status]= */
    public function index(Request $request): JsonResponse
    {
        $q = Membership::query()->with(['plan', 'customer', 'cards'])->where('organization_id', Tenant::organizationId());
        if ($status = $request->query('filter')['status'] ?? null) {
            $q->whereIn('status', array_filter(explode(',', strtoupper((string) $status))));
        }
        if ($term = trim((string) $request->query('q', ''))) {
            $q->where(function ($w) use ($term): void {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $w->where('number', 'like', $like)->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like));
            });
        }
        $page = CursorPage::paginate($q, $request, 'id', 'desc');

        return response()->json(Paged::of($page, fn (Membership $m) => $m->toApi()));
    }

    /** GET /memberships/{id} */
    public function show(string $membership): JsonResponse
    {
        $m = $this->find($membership);
        Owns::membership($m->customer_id);

        return response()->json($m->toApi(withCards: true));
    }

    /** GET /memberships/{id}/history */
    public function history(string $membership): JsonResponse
    {
        $this->find($membership);

        return response()->json(['items' => $this->lifecycle->history($membership), 'nextCursor' => null]);
    }

    /** GET /memberships/{id}/usage */
    public function usage(Request $request, string $membership): JsonResponse
    {
        $this->find($membership);
        $page = CursorPage::paginate(MembershipUsage::query()->where('membership_id', $membership), $request, 'id', 'desc');

        return response()->json(Paged::of($page, fn (MembershipUsage $u) => [
            'id' => $u->id, 'facilityId' => $u->facility_unit_id, 'usedAt' => CarbonImmutable::instance($u->used_at)->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'guests' => $u->guests, 'visitNumber' => $u->visit_number, 'discountPercent' => number_format((float) $u->discount_percent, 2, '.', ''),
        ]));
    }

    /** POST /memberships/validate  (permission membership.validate at facilityId). `consume:false` = check only. */
    public function validateMembership(Request $request): JsonResponse
    {
        $d = $request->validate([
            'facilityId' => ['required', 'uuid'],
            'qrToken' => ['required_without_all:nfcUid,membershipNumber', 'nullable', 'string', 'max:160'],
            'nfcUid' => ['nullable', 'string', 'max:64'],
            'membershipNumber' => ['nullable', 'string', 'max:24'],
            'guests' => ['sometimes', 'integer', 'min:0', 'max:'.config('membership.max_guests_per_visit')],
            'clientRef' => ['sometimes', 'nullable', 'string', 'max:64'],
            'consume' => ['sometimes', 'boolean'],
        ]);
        $result = $this->validator->validate($d + ['deviceId' => RequestContext::deviceId(), 'staffId' => RequestContext::staffId()], $d['facilityId']);

        return response()->json($result);
    }

    /** POST /memberships/{id}/renew (permission membership.sell; Idempotency-Key) */
    public function renew(Request $request, string $membership): JsonResponse
    {
        $d = $request->validate($this->tenderRules());
        $m = $this->find($membership);
        $this->requireSite('membership.sell', $m);

        return response()->json($this->memberships->renew($membership, $d['tenders'] ?? [], RequestContext::staffId())->toApi(withCards: true));
    }

    public function suspend(Request $request, string $membership): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $this->requireSite('membership.manage', $this->find($membership));

        return response()->json($this->memberships->suspend($membership, $d['reason'], RequestContext::staffId())->toApi(withCards: true));
    }

    public function reinstate(Request $request, string $membership): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $this->requireSite('membership.manage', $this->find($membership));

        return response()->json($this->memberships->reinstate($membership, $d['reason'], RequestContext::staffId())->toApi(withCards: true));
    }

    public function cancel(Request $request, string $membership): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $this->requireSite('membership.manage', $this->find($membership));

        return response()->json($this->memberships->cancel($membership, $d['reason'], RequestContext::staffId())->toApi(withCards: true));
    }

    /** POST /memberships/{id}/cards {type:"NFC", uid} */
    public function attachCard(Request $request, string $membership): JsonResponse
    {
        $d = $request->validate(['type' => ['required', 'in:NFC'], 'uid' => ['required', 'string', 'max:64']]);
        $this->requireSite('membership.manage', $this->find($membership));

        return response()->json($this->memberships->attachNfcCard($membership, $d['uid'], RequestContext::staffId())->toApi(), 201);
    }

    /** POST /memberships/{id}/cards/{card}/revoke {status: REVOKED|LOST} */
    public function revokeCard(Request $request, string $membership, string $card): JsonResponse
    {
        $d = $request->validate(['status' => ['sometimes', 'in:REVOKED,LOST']]);
        $this->requireSite('membership.manage', $this->find($membership));

        return response()->json($this->memberships->revokeCard($membership, $card, $d['status'] ?? 'REVOKED', RequestContext::staffId())->toApi());
    }

    private function find(string $id): Membership
    {
        return Membership::query()->with(['plan', 'customer', 'cards'])->where('organization_id', Tenant::organizationId())->find($id)
            ?? throw ApiProblem::notFound('membership_not_found', 'Membership not found.');
    }

    private function requireAt(string $permission, string $facilityId): void
    {
        try {
            $ok = $this->checker->can((string) RequestContext::staffId(), $permission, Scope::facility($facilityId));
        } catch (ModelNotFoundException) {
            throw ApiProblem::notFound('scope_not_found', 'The referenced facility was not found.');
        }
        if (! $ok) {
            throw ApiProblem::forbidden('permission_denied', "Missing permission: {$permission}.", ['permission' => $permission]);
        }
    }

    private function requireSite(string $permission, Membership $m): void
    {
        if (! $this->checker->can((string) RequestContext::staffId(), $permission, Scope::site($m->site_id))) {
            throw ApiProblem::forbidden('permission_denied', "Missing permission: {$permission}.", ['permission' => $permission]);
        }
    }

    private function tenderRules(): array
    {
        return [
            'tenders' => ['sometimes', 'array', 'max:6'],
            'tenders.*.tenderType' => ['required', 'in:CASH,CARD,TRANSFER,POS_TERMINAL'],
            'tenders.*.amount' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?$/'],
            'tenders.*.reference' => ['nullable', 'string', 'max:64'],
            'tenders.*.tendered' => ['nullable', 'string'],
            'tenders.*.id' => ['nullable', 'uuid'],
            'tenders.*.clientCreatedAt' => ['nullable', 'date'],
            'cashSessionId' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
