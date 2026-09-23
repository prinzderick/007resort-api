<?php

namespace App\Domain\Booking\Http\Controllers;

use App\Domain\Booking\Http\Presenters\BookingPresenter;
use App\Domain\Booking\Models\Blackout;
use App\Domain\Booking\Models\BookableResource;
use App\Domain\Booking\Services\AvailabilityService;
use App\Support\Api\Paged;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Ids;
use App\Support\RequestContext;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResourceController
{
    public function __construct(private readonly AvailabilityService $availability) {}

    public function index(Request $request): array
    {
        $q = BookableResource::query()->whereNull('deleted_at')->where('is_active', 1);
        if ($org = Tenant::organizationId()) {
            $q->where('organization_id', $org);
        }
        if (($fac = $request->query('facilityId')) !== null && $fac !== '') {
            if (! Ids::isUuid($fac)) {
                throw ApiProblem::unprocessable('validation_failed', 'facilityId must be a UUID.');
            }
            $q->where('facility_unit_id', strtolower($fac));
        }

        return Paged::envelope(CursorPage::paginate($q, $request, 'id', 'asc'), fn ($r) => BookingPresenter::resource($r));
    }

    public function availability(Request $request, string $resourceId): array
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date']]);
        $resource = $this->find($resourceId);
        $from = CarbonImmutable::parse($data['from'])->utc();
        $to = CarbonImmutable::parse($data['to'])->utc();

        return [
            'resourceId' => $resource->id, 'from' => $from->format('Y-m-d\TH:i:s\Z'), 'to' => $to->format('Y-m-d\TH:i:s\Z'),
            'slots' => $this->availability->slots($resource, $from, $to),
        ];
    }

    /** Manager configuration (booking.configure): create a resource incl. its Booking Authority strategy. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $facility = DB::table('facility_unit')->where('id', Ids::toBinary($data['facilityId']))->first(['organization_id', 'site_id']);
        if ($facility === null || (($org = Tenant::organizationId()) !== null && Ids::fromBinary($facility->organization_id) !== $org)) {
            throw ApiProblem::notFound('not_found', 'Facility not found.');
        }
        $resource = DB::transaction(function () use ($data, $facility) {
            $r = BookableResource::create($this->attributes($data) + [
                'organization_id' => Ids::fromBinary($facility->organization_id), 'site_id' => Ids::fromBinary($facility->site_id), 'facility_unit_id' => strtolower($data['facilityId']),
            ]);
            Audit::record('booking.resource.create', 'BookableResource', $r->id, null, ['name' => $r->name, 'mode' => $r->mode, 'capacity' => $r->capacity], organizationId: $r->organization_id, siteId: $r->site_id, facilityUnitId: $r->facility_unit_id);

            return $r;
        });

        return response()->json(BookingPresenter::resource($resource->refresh()), 201);
    }

    public function update(Request $request, string $resourceId): JsonResponse
    {
        $data = $request->validate($this->rules(false));
        $resource = $this->find($resourceId);
        $old = BookingPresenter::resource($resource);
        DB::transaction(function () use ($resource, $data, $old) {
            $resource->update($this->attributes($data, $resource->capacity) + ['row_version' => $resource->row_version + 1]);
            Audit::record('booking.resource.update', 'BookableResource', $resource->id, $old, BookingPresenter::resource($resource->refresh()), organizationId: $resource->organization_id, siteId: $resource->site_id, facilityUnitId: $resource->facility_unit_id);
        });

        return response()->json(BookingPresenter::resource($resource->refresh()));
    }

    public function blackout(Request $request, string $resourceId): JsonResponse
    {
        $data = $request->validate(['start' => ['required', 'date'], 'end' => ['required', 'date', 'after:start'], 'reason' => ['nullable', 'string', 'max:255']]);
        $resource = $this->find($resourceId);
        $b = DB::transaction(function () use ($data, $resource) {
            $b = Blackout::create([
                'organization_id' => $resource->organization_id, 'resource_id' => $resource->id, 'starts_at' => CarbonImmutable::parse($data['start'])->utc(),
                'ends_at' => CarbonImmutable::parse($data['end'])->utc(), 'reason' => $data['reason'] ?? null, 'created_by' => RequestContext::staffId(),
            ]);
            Audit::record('booking.blackout.create', 'Blackout', $b->id, null, ['resourceId' => $resource->id, 'start' => $data['start'], 'end' => $data['end']], organizationId: $resource->organization_id, siteId: $resource->site_id, facilityUnitId: $resource->facility_unit_id);

            return $b;
        });

        return response()->json(['id' => $b->id, 'resourceId' => $resource->id, 'start' => $b->starts_at->format('Y-m-d\TH:i:s\Z'), 'end' => $b->ends_at->format('Y-m-d\TH:i:s\Z'), 'reason' => $b->reason], 201);
    }

    private function find(string $id): BookableResource
    {
        $r = Ids::isUuid($id) ? BookableResource::query()->whereKey(strtolower($id))->whereNull('deleted_at')->first() : null;
        if ($r === null || (($org = Tenant::organizationId()) !== null && $r->organization_id !== $org)) {
            throw ApiProblem::notFound('not_found', 'Bookable resource not found.');
        }

        return $r;
    }

    /** @return array<string, list<string>> */
    private function rules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return [
            'facilityId' => $create ? ['required', 'uuid'] : ['prohibited'],
            'code' => [$req, 'string', 'max:64'],
            'name' => [$req, 'string', 'max:200'],
            'mode' => [$req, 'in:WHOLE_RESOURCE,INDIVIDUAL_CAPACITY,TIME_SLOT'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'slotMinutes' => ['sometimes', 'integer', 'min:5', 'max:1440'],
            'maxSlotsPerBooking' => ['sometimes', 'integer', 'min:1', 'max:48'],
            'price' => ['sometimes', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
            'wholePrice' => ['sometimes', 'nullable', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
            'productId' => ['sometimes', 'nullable', 'uuid'],
            'ticketTypeId' => ['sometimes', 'nullable', 'uuid'],
            'allowWholeResource' => ['sometimes', 'boolean'],
            'onlineBookable' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'authority' => ['sometimes', 'array'],
            'authority.offlineStrategy' => ['sometimes', 'in:A_OFFLINE_ALLOCATION,B_ONLINE_AUTHORITY_REQUIRED,C_DISABLE_ONLINE'],
            'authority.localReserveUnits' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'authority.onlineStaleAfterSeconds' => ['sometimes', 'integer', 'min:30', 'max:604800'],
        ];
    }

    /** @return array<string, mixed> */
    private function attributes(array $d, ?int $currentCapacity = null): array
    {
        $map = ['code' => 'code', 'name' => 'name', 'mode' => 'mode', 'capacity' => 'capacity', 'slotMinutes' => 'slot_minutes', 'maxSlotsPerBooking' => 'max_slots_per_booking',
            'price' => 'price', 'wholePrice' => 'whole_price', 'productId' => 'product_id', 'ticketTypeId' => 'ticket_type_id', 'allowWholeResource' => 'allow_whole_resource',
            'onlineBookable' => 'online_bookable', 'active' => 'is_active'];
        $out = [];
        foreach ($map as $k => $col) {
            if (array_key_exists($k, $d)) {
                $out[$col] = $d[$k];
            }
        }
        foreach (['offlineStrategy' => 'offline_strategy', 'localReserveUnits' => 'local_reserve_units', 'onlineStaleAfterSeconds' => 'online_stale_after_seconds'] as $k => $col) {
            if (isset($d['authority']) && array_key_exists($k, $d['authority'])) {
                $out[$col] = $d['authority'][$k];
            }
        }
        $cap = $out['capacity'] ?? $currentCapacity ?? 1;
        if (isset($out['local_reserve_units']) && $out['local_reserve_units'] > $cap) {
            throw ApiProblem::unprocessable('validation_failed', 'localReserveUnits cannot exceed capacity.', ['authority.localReserveUnits' => ['exceeds capacity']]);
        }

        return $out;
    }
}
