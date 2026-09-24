<?php

namespace App\Domain\Audit\Http\Controllers;

use App\Domain\Identity\Services\PermissionChecker;
use App\Support\Audit\Audit;
use App\Support\Http\CursorPage;
use App\Support\Ids;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditController
{
    /** Entity types a `config.view` holder (without audit.view) may read: the configuration change history. */
    public const CONFIG_ENTITY_TYPES = ['Facility', 'OperatingPoint', 'DiningTable', 'Product', 'ProductCategory', 'ProductFacility', 'PriceList', 'Price', 'TaxRate', 'PrepRoute', 'TicketType', 'Role', 'Device',
        'ReceiptSetting', 'BusinessProfile', 'MembershipPlan', 'BookableResource', 'Blackout', 'OrganizationTaxSetting', 'Organization'];

    /**
     * GET /audit?entityType=&entityTypes=a,b&entityId=&actorStaffId=&action=&actionPrefix=&facilityId=&from=&to=&order=asc|desc&limit=&cursor=
     * (oldest first unless order=desc; from/to are UTC dates or ISO instants, `to` inclusive for a bare date; `filter[from]`/`filter[to]` also accepted). Rows include `actorName`.
     */
    public function index(Request $request): JsonResponse
    {
        $q = DB::table('audit_log');
        $staff = RequestContext::staffId();
        if ($staff !== null && ! app(PermissionChecker::class)->can($staff, 'audit.view')) {
            $q->whereIn('entity_type', self::CONFIG_ENTITY_TYPES);
        }
        if ($v = $request->query('entityTypes')) {
            $q->whereIn('entity_type', array_filter(array_map('trim', explode(',', (string) $v))));
        }
        if ($v = $request->query('actionPrefix')) {
            $q->where('action', 'like', addcslashes((string) $v, '%_\\').'%');
        }
        if (($v = $request->query('facilityId')) && Ids::isUuid($v)) {
            $q->where('facility_unit_id', Ids::toBinary($v));
        }
        if ($v = $request->query('entityType')) {
            $q->where('entity_type', $v);
        }
        if (($v = $request->query('entityId')) && Ids::isUuid($v)) {
            $q->where('entity_id', Ids::toBinary($v));
        }
        if (($v = $request->query('actorStaffId')) && Ids::isUuid($v)) {
            $q->where('actor_staff_id', Ids::toBinary($v));
        }
        if ($v = $request->query('action')) {
            $q->where('action', $v);
        }

        $request->validate(['order' => ['nullable', 'in:asc,desc'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        // Both spellings are accepted: ?from= and the contract's filter[from]= convention.
        $filter = (array) $request->query('filter', []);
        if ($v = $request->query('from', $filter['from'] ?? null)) {
            $q->where('occurred_at', '>=', CarbonImmutable::parse($v, 'UTC')->utc()->format('Y-m-d H:i:s.u'));
        }
        if ($v = $request->query('to', $filter['to'] ?? null)) {
            $to = CarbonImmutable::parse($v, 'UTC')->utc();
            $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? $to->addDay() : $to->addMicrosecond();
            $q->where('occurred_at', '<', $to->format('Y-m-d H:i:s.u'));
        }

        $page = CursorPage::paginate($q, $request, 'seq', $request->query('order') === 'desc' ? 'desc' : 'asc');
        $names = DB::table('staff')->whereIn('id', $page->items->pluck('actor_staff_id')->filter()->unique()->all())->get(['id', 'first_name', 'last_name'])->mapWithKeys(fn ($s) => [$s->id => trim($s->first_name.' '.$s->last_name)]);

        return response()->json($page->toArray(fn ($r) => [
            'id' => Ids::fromBinary($r->id),
            'seq' => (int) $r->seq,
            'occurredAt' => CarbonImmutable::parse($r->occurred_at, 'UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'organizationId' => Ids::fromBinary($r->organization_id),
            'siteId' => Ids::fromBinary($r->site_id),
            'actorStaffId' => $r->actor_staff_id ? Ids::fromBinary($r->actor_staff_id) : null,
            'actorName' => $r->actor_staff_id ? ($names[$r->actor_staff_id] ?? null) : null,
            'facilityUnitId' => $r->facility_unit_id ? Ids::fromBinary($r->facility_unit_id) : null,
            'deviceId' => $r->device_id ? Ids::fromBinary($r->device_id) : null,
            'action' => $r->action,
            'entityType' => $r->entity_type,
            'entityId' => Ids::fromBinary($r->entity_id),
            'oldValue' => $r->old_value === null ? null : json_decode($r->old_value),
            'newValue' => $r->new_value === null ? null : json_decode($r->new_value),
            'approvalId' => $r->approval_id ? Ids::fromBinary($r->approval_id) : null,
            'rowHash' => $r->row_hash,
        ]));
    }

    /** GET /audit/verify — recompute the whole hash chain. */
    public function verify(): JsonResponse
    {
        return response()->json(Audit::verifyChain()->toArray());
    }
}
