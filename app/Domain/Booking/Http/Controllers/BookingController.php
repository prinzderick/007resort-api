<?php

namespace App\Domain\Booking\Http\Controllers;

use App\Domain\Booking\Http\Presenters\BookingPresenter;
use App\Domain\Booking\Http\Presenters\Preconditions;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingService;
use App\Domain\Booking\Support\HoldCommand;
use App\Domain\Customer\Support\Actor;
use App\Domain\Customer\Support\Owns;
use App\Domain\Guest\Services\GuestCheckout;
use App\Domain\Identity\Models\Customer;
use App\Support\Api\Paged;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController
{
    public function __construct(private readonly BookingService $bookings) {}

    public function hold(Request $request): JsonResponse
    {
        $data = $request->validate([
            'resourceId' => ['required', 'uuid'],
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'wholeResource' => ['nullable', 'boolean'],
            'customer' => ['nullable', 'array'],
            'customer.name' => ['required_with:customer', 'string', 'max:160'],
            'customer.phone' => ['nullable', 'string', 'max:32'],
            'customer.email' => ['nullable', 'email', 'max:255'],
            'customer.membershipId' => ['nullable', 'uuid'],
            'guest' => ['nullable', 'array'],
        ]);
        $channel = 'STAFF';
        $customerId = null;
        $guests = app(GuestCheckout::class);
        $guest = null;
        if ($guests->isGuestRequest()) { // website service token: checkout without an account (docs/GUEST_CHECKOUT.md)
            $guest = $guests->contact($data['guest'] ?? null, $request);
            $data['customer'] = ['name' => $guest->name, 'email' => $guest->email, 'phone' => $guest->phone]; // membershipId is never accepted from guests
            $channel = 'ONLINE';
        } elseif (isset($data['guest'])) {
            throw ApiProblem::unprocessable('validation_failed', 'guest is only for checkout without an account.', ['guest' => ['not allowed here']]);
        } elseif (Actor::isCustomer()) { // an online customer books as THEMSELVES: identity comes from the account, never from the body
            $c = Customer::query()->findOrFail(Actor::customerId());
            $data['customer'] = ['name' => $c->full_name, 'email' => $c->email, 'phone' => $data['customer']['phone'] ?? $c->phone];
            $channel = 'ONLINE';
            $customerId = $c->id;
        }
        $booking = $this->bookings->hold(new HoldCommand(
            resourceId: strtolower($data['resourceId']),
            start: CarbonImmutable::parse($data['start'])->utc(),
            end: CarbonImmutable::parse($data['end'])->utc(),
            quantity: (int) ($data['quantity'] ?? 1),
            wholeResource: (bool) ($data['wholeResource'] ?? false),
            customer: $data['customer'] ?? null,
            channel: $channel,
            customerId: $customerId,
        ));
        $access = $guest === null ? null : $guests->open($guest, 'BOOKING', $booking->id);

        return $this->respond($booking, 201, $access);
    }

    public function index(Request $request): array
    {
        $request->validate(['filter.from' => ['nullable', 'date'], 'filter.to' => ['nullable', 'date']]);
        $q = Booking::query()->with('resource');
        if ($org = Tenant::organizationId()) {
            $q->where('organization_id', $org);
        }
        $f = (array) $request->query('filter', []);
        foreach (['resourceId' => 'resource_id', 'facilityId' => 'facility_unit_id'] as $param => $col) {
            if (! empty($f[$param])) {
                if (! Ids::isUuid($f[$param])) {
                    throw ApiProblem::unprocessable('validation_failed', "filter[{$param}] must be a UUID.");
                }
                $q->where($col, strtolower($f[$param]));
            }
        }
        if (! empty($f['status'])) {
            $q->whereIn('status', array_map('strtoupper', explode(',', (string) $f['status'])));
        }
        if (! empty($f['from'])) {
            $q->where('start_at', '>=', CarbonImmutable::parse($f['from'])->utc()->format('Y-m-d H:i:s.u'));
        }
        if (! empty($f['to'])) {
            $q->where('start_at', '<', CarbonImmutable::parse($f['to'])->utc()->format('Y-m-d H:i:s.u'));
        }
        if (($term = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('customer_name', 'like', $like)->orWhere('customer_phone', 'like', $like));
        }

        return Paged::envelope(CursorPage::paginate($q, $request, 'id', 'desc'), fn ($b) => BookingPresenter::booking($b));
    }

    public function show(string $bookingId): JsonResponse
    {
        $booking = Booking::query()->with('resource')->whereKey($this->id($bookingId))->first();
        if ($booking === null || (($org = Tenant::organizationId()) !== null && $booking->organization_id !== $org)) {
            throw ApiProblem::notFound('not_found', 'Booking not found.');
        }

        Owns::booking($booking->customer_id, $booking->id);

        return $this->respond($booking);
    }

    public function confirm(Request $request, string $bookingId): JsonResponse
    {
        $data = $request->validate([
            'tenders' => ['nullable', 'array', 'max:10'],
            'tenders.*.tenderType' => ['required', 'in:CASH,CARD,TRANSFER,POS_TERMINAL'],
            'tenders.*.amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
            'tenders.*.reference' => ['nullable', 'string', 'max:128'],
            'tenders.*.tendered' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
            'cashSessionId' => ['nullable', 'uuid'],
            'paystackReference' => ['nullable', 'string', 'max:128'],
        ]);
        $this->assertOwned($bookingId);
        if (! Actor::isStaff() && (! empty($data['tenders']) || ! empty($data['cashSessionId']))) {
            throw ApiProblem::forbidden('permission_denied', 'Online customers pay through Paystack; tenders are for staff.');
        }
        $booking = $this->bookings->confirm($this->id($bookingId), Preconditions::rowVersion($request), $data['tenders'] ?? [], $data['cashSessionId'] ?? null, $data['paystackReference'] ?? null);

        return $this->respond($booking);
    }

    public function cancel(Request $request, string $bookingId): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->assertOwned($bookingId);

        return $this->respond($this->bookings->cancel($this->id($bookingId), Preconditions::rowVersion($request), $data['reason']));
    }

    public function reschedule(Request $request, string $bookingId): JsonResponse
    {
        $data = $request->validate(['start' => ['required', 'date'], 'end' => ['required', 'date', 'after:start'], 'reason' => ['nullable', 'string', 'max:255']]);
        $this->assertOwned($bookingId);
        $booking = $this->bookings->reschedule($this->id($bookingId), Preconditions::rowVersion($request), CarbonImmutable::parse($data['start'])->utc(), CarbonImmutable::parse($data['end'])->utc(), $data['reason'] ?? null);

        return $this->respond($booking);
    }

    /** POST /bookings/{id}/order — Reception flow: attach the order (slot fee + rentals + store items) that will pay for this hold. */
    public function attachOrder(Request $request, string $bookingId): JsonResponse
    {
        $data = $request->validate(['orderId' => ['required', 'uuid']]);

        return $this->respond($this->bookings->attachOrder($this->id($bookingId), Preconditions::rowVersion($request), strtolower($data['orderId'])));
    }

    /** Customers may only touch their own bookings (404 otherwise); staff are gated by permission middleware. */
    private function assertOwned(string $bookingId): void
    {
        if (! Actor::isStaff()) {
            $owner = Ids::isUuid($bookingId) ? Booking::query()->whereKey(strtolower($bookingId))->value('customer_id') : null;
            Owns::booking($owner, Ids::isUuid($bookingId) ? $bookingId : null);
        }
    }

    private function id(string $id): string
    {
        if (! Ids::isUuid($id)) {
            throw ApiProblem::notFound('not_found', 'Booking not found.');
        }

        return strtolower($id);
    }

    /** @param array<string, mixed>|null $guestAccess guest checkout: the one-time order access token block */
    private function respond(Booking $b, int $status = 200, ?array $guestAccess = null): JsonResponse
    {
        return response()->json(BookingPresenter::booking($b) + ($guestAccess === null ? [] : ['guestAccess' => $guestAccess]), $status)->header('ETag', Preconditions::etag($b->row_version));
    }
}
