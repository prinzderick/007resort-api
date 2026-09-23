<?php

namespace App\Domain\Ticketing\Http\Controllers;

use App\Domain\Booking\Models\Booking;
use App\Domain\Customer\Support\Owns;
use App\Domain\Ticketing\Contracts\OrderLineSource;
use App\Domain\Ticketing\Http\Presenters\EntitlementPresenter;
use App\Domain\Ticketing\Models\Entitlement;
use App\Domain\Ticketing\Services\EntitlementService;
use App\Domain\Ticketing\Services\OrderTicketing;
use App\Domain\Ticketing\Services\RedemptionService;
use App\Support\Api\Paged;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EntitlementController
{
    public function __construct(
        private readonly EntitlementService $issuer,
        private readonly RedemptionService $redemptions,
        private readonly OrderLineSource $orders,
        private readonly OrderTicketing $orderTickets,
    ) {}

    public function index(Request $request): array
    {
        $q = Entitlement::query()->with('items');
        if ($org = Tenant::organizationId()) {
            $q->where('organization_id', $org);
        }
        $f = (array) $request->query('filter', []);
        foreach (['orderId' => 'order_id', 'bookingId' => 'booking_id'] as $param => $col) {
            if (! empty($f[$param])) {
                if (! Ids::isUuid($f[$param])) {
                    throw ApiProblem::unprocessable('validation_failed', "filter[{$param}] must be a UUID.");
                }
                $q->where($col, strtolower($f[$param]));
            }
        }

        return Paged::envelope(CursorPage::paginate($q, $request, 'id', 'desc'), fn ($e) => EntitlementPresenter::entitlement($e));
    }

    /** POST /entitlements — manual / re-issue. Idempotent per source. */
    public function issue(Request $request): JsonResponse
    {
        $data = $request->validate(['orderId' => ['nullable', 'uuid', 'required_without:bookingId'], 'bookingId' => ['nullable', 'uuid', 'required_without:orderId', 'prohibits:orderId']]);

        return DB::transaction(function () use ($data) {
            if (! empty($data['bookingId'])) {
                $b = Booking::query()->with('resource')->whereKey(strtolower($data['bookingId']))->first();
                if ($b === null || (($org = Tenant::organizationId()) !== null && $b->organization_id !== $org)) {
                    throw ApiProblem::notFound('not_found', 'Booking not found.');
                }
                if (! in_array($b->status, [Booking::CONFIRMED, Booking::RESCHEDULED, Booking::COMPLETED], true)) {
                    throw ApiProblem::conflict('booking_state_invalid', 'Only a confirmed booking can be issued an entitlement.');
                }
                $ent = $this->issuer->issueForBooking($b);
                DB::table('booking')->where('id', Ids::toBinary($b->id))->whereNull('entitlement_id')->update(['entitlement_id' => Ids::toBinary($ent->id)]);

                return response()->json(EntitlementPresenter::entitlement($ent), 201);
            }
            $orderId = strtolower($data['orderId']);
            $order = $this->orders->forOrder($orderId) ?? throw ApiProblem::notFound('not_found', 'Order not found.');
            if (($org = Tenant::organizationId()) !== null && $order['organizationId'] !== $org) {
                throw ApiProblem::notFound('not_found', 'Order not found.');
            }
            if (! $order['fullyPaid']) {
                throw ApiProblem::conflict('payment_state_invalid', 'Entitlements are issued only for a fully paid order.');
            }
            $list = $this->orderTickets->issueForPaidOrder($orderId);
            if ($list === []) {
                throw ApiProblem::unprocessable('validation_failed', 'The order has no ticket or rental lines.');
            }
            $body = EntitlementPresenter::entitlement($list[0]);
            if (count($list) > 1) {
                $body['groupEntitlementIds'] = array_map(fn ($e) => $e->id, $list);
            }

            return response()->json($body, 201);
        });
    }

    public function show(string $entitlementId): JsonResponse
    {
        $e = $this->find($entitlementId);
        Owns::entitlement($e);

        return response()->json(EntitlementPresenter::entitlement($e));
    }

    public function byToken(string $qrToken): JsonResponse
    {
        return response()->json(EntitlementPresenter::entitlement($this->redemptions->lookup($qrToken)));
    }

    public function redeem(Request $request, string $qrToken): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:ENTRY'],
            'facilityId' => ['nullable', 'uuid'],
            'entitlementItemId' => ['nullable', 'uuid'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'override' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->redemptions->redeem(
            $qrToken, $data['facilityId'] ?? null, isset($data['entitlementItemId']) ? strtolower($data['entitlementItemId']) : null, (int) ($data['quantity'] ?? 1), (bool) ($data['override'] ?? false),
        ));
    }

    public function exit(Request $request, string $qrToken): JsonResponse
    {
        $data = $request->validate(['facilityId' => ['nullable', 'uuid'], 'entitlementItemId' => ['nullable', 'uuid']]);

        return response()->json($this->redemptions->exit($qrToken, $data['facilityId'] ?? null, isset($data['entitlementItemId']) ? strtolower($data['entitlementItemId']) : null));
    }

    public function release(Request $request, string $entitlementId): JsonResponse
    {
        $data = $request->validate([
            'itemIds' => ['required', 'array', 'min:1', 'max:50'], 'itemIds.*' => ['uuid'],
            'note' => ['nullable', 'string', 'max:255'], 'depositCollected' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
        ]);
        $ent = $this->redemptions->release($this->id($entitlementId), array_map('strtolower', $data['itemIds']), $data['note'] ?? null, $data['depositCollected'] ?? null);

        return response()->json(EntitlementPresenter::entitlement($ent));
    }

    public function return(Request $request, string $entitlementId): JsonResponse
    {
        $data = $request->validate([
            'itemIds' => ['required', 'array', 'min:1', 'max:50'], 'itemIds.*' => ['uuid'], 'condition' => ['nullable', 'in:OK,DAMAGED,LOST'],
            'note' => ['nullable', 'string', 'max:255'], 'damageCharge' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
        ]);
        $ent = $this->redemptions->return($this->id($entitlementId), array_map('strtolower', $data['itemIds']), $data['condition'] ?? 'OK', $data['note'] ?? null, $data['damageCharge'] ?? null);

        return response()->json(EntitlementPresenter::entitlement($ent));
    }

    private function id(string $id): string
    {
        if (! Ids::isUuid($id)) {
            throw ApiProblem::notFound('not_found', 'Entitlement not found.');
        }

        return strtolower($id);
    }

    private function find(string $id): Entitlement
    {
        $e = Entitlement::query()->with('items')->whereKey($this->id($id))->first();
        if ($e === null || (($org = Tenant::organizationId()) !== null && $e->organization_id !== $org)) {
            throw ApiProblem::notFound('not_found', 'Entitlement not found.');
        }

        return $e;
    }
}
