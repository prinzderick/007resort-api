<?php

namespace App\Domain\Config\Services;

use App\Domain\Identity\Services\PermissionChecker;
use App\Support\Ids;
use App\Support\RequestContext;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

/** GET /admin/search: global search, each result type returned only if the caller holds a permission for it. */
class SearchService
{
    /** type => permission codes (any-of). */
    public const TYPES = [
        'staff' => ['staff.manage'],
        'product' => ['catalog.manage', 'pricing.manage', 'order.create', 'inventory.view'],
        'facility' => ['config.view', 'facility.manage', 'order.view'],
        'order' => ['order.view'],
        'receipt' => ['receipt.view'],
        'customer' => ['membership.view', 'booking.view'],
    ];

    public function __construct(private readonly PermissionChecker $permissions) {}

    /** @param list<string>|null $types @return array{q: string, results: object} */
    public function search(string $q, ?array $types, int $limit): array
    {
        $staff = RequestContext::staffId();
        $org = Ids::toBinary((string) Tenant::organizationId());
        $site = Ids::toBinary((string) Tenant::siteId());
        $like = '%'.addcslashes($q, '%_\\').'%';
        $prefix = addcslashes($q, '%_\\').'%';
        $out = [];
        foreach (self::TYPES as $type => $perms) {
            if ($types !== null && ! in_array($type, $types, true)) {
                continue;
            }
            if (! collect($perms)->contains(fn ($p) => $this->permissions->can($staff, $p))) {
                continue;
            }
            $out[$type] = match ($type) {
                'staff' => DB::table('staff as s')->leftJoin('user_account as u', 'u.staff_id', '=', 's.id')->where('s.site_id', $site)->whereNull('s.deleted_at')
                    ->where(fn ($w) => $w->where('s.first_name', 'like', $like)->orWhere('s.last_name', 'like', $like)->orWhere('s.staff_number', 'like', $prefix)->orWhere('u.username', 'like', $prefix)->orWhere('s.email', 'like', $prefix)
                        ->orWhereRaw("CONCAT(s.first_name, ' ', s.last_name) LIKE ?", [$like]))->orderBy('s.last_name')->limit($limit)->get(['s.id', 's.first_name', 's.last_name', 's.staff_number', 's.is_active', 'u.username'])
                    ->map(fn ($r) => ['type' => 'staff', 'id' => Ids::fromBinary($r->id), 'title' => trim($r->first_name.' '.$r->last_name), 'subtitle' => $r->staff_number.($r->username ? ' - '.$r->username : '').($r->is_active ? '' : ' (inactive)'), 'ref' => '/staff/'.Ids::fromBinary($r->id)])->all(),
                'product' => DB::table('product')->where('organization_id', $org)->whereNull('deleted_at')->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $prefix)->orWhere('barcode', 'like', $prefix))
                    ->orderBy('name')->limit($limit)->get(['id', 'sku', 'name', 'is_active'])->map(fn ($r) => ['type' => 'product', 'id' => Ids::fromBinary($r->id), 'title' => $r->name, 'subtitle' => $r->sku.($r->is_active ? '' : ' (inactive)'), 'ref' => '/admin/catalog/products/'.Ids::fromBinary($r->id)])->all(),
                'facility' => DB::table('facility_unit')->where('site_id', $site)->whereNull('deleted_at')->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $prefix))->orderBy('name')->limit($limit)->get(['id', 'code', 'name', 'kind', 'is_active'])
                    ->map(fn ($r) => ['type' => 'facility', 'id' => Ids::fromBinary($r->id), 'title' => $r->name, 'subtitle' => $r->code.' - '.$r->kind.($r->is_active ? '' : ' (inactive)'), 'ref' => '/organization/facilities/'.Ids::fromBinary($r->id)])->all(),
                'order' => DB::table('order')->where('site_id', $site)->where('order_number', 'like', $prefix)->orderByDesc('created_at')->limit($limit)->get(['id', 'order_number', 'status', 'total', 'facility_unit_id'])
                    ->map(fn ($r) => ['type' => 'order', 'id' => Ids::fromBinary($r->id), 'title' => $r->order_number, 'subtitle' => $r->status.' - '.number_format((float) $r->total, 2), 'ref' => '/orders/'.Ids::fromBinary($r->id)])->all(),
                'receipt' => DB::table('receipt')->where('site_id', $site)->where('number', 'like', $prefix)->orderByDesc('created_at')->limit($limit)->get(['id', 'number', 'amount_paid'])
                    ->map(fn ($r) => ['type' => 'receipt', 'id' => Ids::fromBinary($r->id), 'title' => $r->number, 'subtitle' => number_format((float) $r->amount_paid, 2), 'ref' => '/receipts/'.Ids::fromBinary($r->id)])->all(),
                'customer' => DB::table('customer')->where('organization_id', $org)->whereNull('deleted_at')->where(fn ($w) => $w->where('full_name', 'like', $like)->orWhere('phone', 'like', $prefix)->orWhere('email', 'like', $prefix))->orderBy('full_name')->limit($limit)
                    ->get(['id', 'full_name', 'phone', 'email'])->map(fn ($r) => ['type' => 'customer', 'id' => Ids::fromBinary($r->id), 'title' => $r->full_name, 'subtitle' => implode(' - ', array_filter([$r->phone, $r->email])), 'ref' => '/customers/'.Ids::fromBinary($r->id)])->all(),
            };
        }

        return ['q' => $q, 'results' => (object) $out];
    }
}
