<?php

namespace App\Domain\Booking\Http\Controllers;

use App\Domain\Booking\Http\Presenters\BookingPresenter;
use App\Domain\Booking\Http\Presenters\Preconditions;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingService;
use App\Domain\Booking\Support\HoldCommand;
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
        ]);
        $booking = $this->bookings->hold(new HoldCommand(
            resourceId: strtolower($data['resourceId']),
            start: CarbonImmutable::parse($data['start'])->utc(),
            end: CarbonImmutable::parse($data['end'])->utc(),
            quantity: (int) ($data['quantity'] ?? 1),
            wholeResource: (bool) ($data['wholeResource'] ?? false),
            customer: $data['customer'] ?? null,
        ));

        return $this->respond($booking, 201);
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
        $page = CursorPage::paginate($q, $request, 'id', 'desc')->toArray(fn ($b) => BookingPresenter::booking($b));

        return ['items' => $page['data'], 'nextCursor' => $page['page']['nextCursor']];
    }

    public function show(string $bookingId): JsonResponse
    {
        $booking = Booking::query()->with('resource')->whereKey($this->id($bookingId))->first();
        if ($booking === null || (($org = Tenant::organizationId()) !== null && $booking->organization_id !== $org)) {
            throw ApiProblem::notFound('not_found', 'Booking not found.');
        }

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
        $booking = $this->bookings->confirm($this->id($bookingId), Preconditions::rowVersion($request), $data['tenders'] ?? [], $data['cashSessionId'] ?? null, $data['paystackReference'] ?? null);

        return $this->respond($booking);
    }

    public function cancel(Request $request, string $bookingId): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->respond($this->bookings->cancel($this->id($bookingId), Preconditions::rowVersion($request), $data['reason']));
    }

    public function reschedule(Request $request, string $bookingId): JsonResponse
    {
        $data = $request->validate(['start' => ['required', 'date'], 'end' => ['required', 'date', 'after:start'], 'reason' => ['nullable', 'string', 'max:255']]);
        $booking = $this->bookings->reschedule($this->id($bookingId), Preconditions::rowVersion($request), CarbonImmutable::parse($data['start'])->utc(), CarbonImmutable::parse($data['end'])->utc(), $data['reason'] ?? null);

        return $this->respond($booking);
    }

    private function id(string $id): string
    {
        if (! Ids::isUuid($id)) {
            throw ApiProblem::notFound('not_found', 'Booking not found.');
        }

        return strtolower($id);
    }

    private function respond(Booking $b, int $status = 200): JsonResponse
    {
        return response()->json(BookingPresenter::booking($b), $status)->header('ETag', Preconditions::etag($b->row_version));
    }
}
