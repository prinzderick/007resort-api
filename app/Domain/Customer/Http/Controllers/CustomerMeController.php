<?php

namespace App\Domain\Customer\Http\Controllers;

use App\Domain\Booking\Http\Presenters\BookingPresenter;
use App\Domain\Booking\Models\Booking;
use App\Domain\Customer\Services\CustomerProfileService;
use App\Domain\Customer\Services\TicketOrderService;
use App\Domain\Customer\Support\Actor;
use App\Domain\Customer\Support\Owns;
use App\Domain\Identity\Models\Customer;
use App\Domain\Membership\Models\Membership;
use App\Domain\Ticketing\Http\Presenters\EntitlementPresenter;
use App\Domain\Ticketing\Models\Entitlement;
use App\Support\Api\Paged;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Ids;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Everything here is scoped to the signed-in customer by construction (WHERE customer_id = me), not by post-filtering. */
class CustomerMeController
{
    public function me(): JsonResponse
    {
        return response()->json(app(CustomerProfileService::class)->present(Actor::requireCustomer()));
    }

    public function bookings(Request $request): array
    {
        $q = Booking::query()->with('resource')->where('customer_id', Actor::requireCustomer());
        if (! empty($request->query('filter')['status'])) {
            $q->whereIn('status', array_map('strtoupper', explode(',', (string) $request->query('filter')['status'])));
        }

        return Paged::envelope(CursorPage::paginate($q, $request, 'id', 'desc'), fn ($b) => BookingPresenter::booking($b));
    }

    public function entitlements(Request $request): array
    {
        $me = Ids::toBinary(Actor::requireCustomer());
        $q = Entitlement::query()->with('items')->where(function ($w) use ($me) {
            $w->where('customer_id', Ids::fromBinary($me))
                ->orWhereIn('booking_id', DB::table('booking')->select('id')->where('customer_id', $me))
                ->orWhereIn('order_id', DB::table('customer_order')->select('order_id')->where('customer_id', $me));
        });
        $f = array_merge((array) $request->query('filter', []), array_filter(['bookingId' => $request->query('bookingId'), 'orderId' => $request->query('orderId')]));
        foreach (['orderId' => 'order_id', 'bookingId' => 'booking_id'] as $param => $col) {
            if (! empty($f[$param])) {
                if (! Ids::isUuid($f[$param])) {
                    throw ApiProblem::unprocessable('validation_failed', "{$param} must be a UUID.");
                }
                $q->where($col, strtolower($f[$param]));
            }
        }

        return Paged::envelope(CursorPage::paginate($q, $request, 'id', 'desc'), fn ($e) => EntitlementPresenter::entitlement($e));
    }

    public function memberships(Request $request): array
    {
        $q = Membership::query()->with(['plan', 'customer', 'cards'])->where('customer_id', Actor::requireCustomer());

        return Paged::envelope(CursorPage::paginate($q, $request, 'id', 'desc'), fn (Membership $m) => $m->toApi(withCards: true));
    }

    public function order(string $orderId, TicketOrderService $orders): JsonResponse
    {
        Actor::requireCustomer();
        if (! Ids::isUuid($orderId)) {
            throw Actor::notYours('Order');
        }
        Owns::order(strtolower($orderId));

        return response()->json($orders->present(strtolower($orderId)));
    }
}
