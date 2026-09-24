<?php

namespace App\Domain\Config\Services;

use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

/** GET /admin/setup-status: onboarding checklist with completion + counts and what is missing. */
class SetupStatusService
{
    /** @return array<string, mixed> */
    public function status(): array
    {
        $org = Ids::toBinary((string) Tenant::organizationId());
        $site = Ids::toBinary((string) Tenant::siteId());
        $cap = fn (string ...$codes) => DB::table('facility_capability as c')->join('facility_unit as f', 'f.id', '=', 'c.facility_unit_id')->where('f.site_id', $site)->where('f.is_active', 1)
            ->whereNull('f.deleted_at')->where('c.is_enabled', 1)->whereIn('c.capability_code', $codes)->distinct()->count('f.id');

        $facilities = (int) DB::table('facility_unit')->where('site_id', $site)->where('is_active', 1)->whereNull('deleted_at')->count();
        $configured = (int) DB::table('facility_unit as f')->where('f.site_id', $site)->where('f.is_active', 1)->whereNull('f.deleted_at')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('facility_capability as c')->whereColumn('c.facility_unit_id', 'f.id')->where('c.is_enabled', 1))->count();
        $products = (int) DB::table('product')->where('organization_id', $org)->where('is_active', 1)->whereNull('deleted_at')->count();
        $now = now('UTC')->format('Y-m-d H:i:s.u');
        $priced = (int) DB::table('product as p')->where('p.organization_id', $org)->where('p.is_active', 1)->whereNull('p.deleted_at')->whereExists(fn ($q) => $q->select(DB::raw(1))->from('price as x')
            ->whereColumn('x.product_id', 'p.id')->where('x.is_active', 1)->where('x.valid_from', '<=', $now)->where(fn ($w) => $w->whereNull('x.valid_to')->orWhere('x.valid_to', '>', $now)))->count();
        $taxRates = (int) DB::table('tax_rate')->where('organization_id', $org)->where('is_active', 1)->count();
        $taxReviewed = DB::table('organization_tax_setting')->where('organization_id', $org)->exists();
        $staff = (int) DB::table('staff')->where('site_id', $site)->where('is_active', 1)->whereNull('deleted_at')->count();
        $assigned = (int) DB::table('role_assignment')->where('organization_id', $org)->where('is_active', 1)->whereNull('deleted_at')->distinct()->count('staff_id');
        $customRoles = (int) DB::table('role')->where('is_system', 0)->count();
        $devices = (int) DB::table('device')->where('site_id', $site)->where('is_revoked', 0)->where('is_active', 1)->count();
        $stations = (int) DB::table('kds_station')->where('site_id', $site)->where('is_active', 1)->count();
        $routing = $cap('KITCHEN_ROUTING', 'BAR_ROUTING');
        $payFacilities = $cap('PAYMENT_ACCEPTANCE');
        $methodsOk = $payFacilities === 0 ? false : DB::table('facility_unit as f')->where('f.site_id', $site)->where('f.is_active', 1)->whereNull('f.deleted_at')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('facility_capability as c')->whereColumn('c.facility_unit_id', 'f.id')->where('c.capability_code', 'PAYMENT_ACCEPTANCE')->where('c.is_enabled', 1))
            ->where(fn ($w) => $w->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('facility_payment_method as m')->whereColumn('m.facility_unit_id', 'f.id'))
                ->orWhereExists(fn ($q) => $q->select(DB::raw(1))->from('facility_payment_method as m')->whereColumn('m.facility_unit_id', 'f.id')->where('m.is_enabled', 1)->whereIn('m.method', SettingsService::STAFF_TENDERS)))->exists();
        $receipt = DB::table('receipt_setting')->where('organization_id', $org)->exists();
        $bookingFacilities = $cap('BOOKING');
        $resources = (int) DB::table('bookable_resource')->where('site_id', $site)->where('is_active', 1)->whereNull('deleted_at')->count();
        $tableFacilities = $cap('TABLE_SERVICE');
        $tables = (int) DB::table('dining_table')->where('site_id', $site)->where('is_active', 1)->count();
        $siteRow = DB::table('site')->where('id', $site)->first();

        $steps = [
            ['business_profile', 'Business profile', ! empty($siteRow->address) && ! empty($siteRow->time_zone), true, null, 'Add the address and phone number that appear on receipts.'],
            ['facilities', 'Facilities set up', $configured > 0, true, $configured, $facilities === 0 ? 'Create your first facility (restaurant, bar, spa...).' : ($facilities - $configured).' facility(ies) have no capabilities yet.'],
            ['products', 'Products', $products > 0, true, $products, 'Add the products you sell (or import them from a CSV file).'],
            ['prices', 'Prices for every product', $products > 0 && $priced === $products, true, $priced, ($products - $priced).' product(s) have no price yet.'],
            ['tax', 'Tax', $taxReviewed || $taxRates > 0, true, $taxRates, 'Review the VAT settings (VAT is off by default).'],
            ['staff', 'Staff', $staff >= 2, true, $staff, 'Add your staff (at least the owner and one operator).'],
            ['roles', 'Roles assigned', $assigned >= 2, true, $assigned, 'Give staff roles so they can log in and work.'],
            ['devices', 'Devices registered', $devices > 0, true, $devices, 'Register the tablets, POS terminals and screens.'],
            ['kds_stations', 'Kitchen/bar screens', $stations > 0, $routing > 0, $stations, 'Create a kitchen or bar station so orders can be prepared.'],
            ['payment_methods', 'Payment methods', $methodsOk, $payFacilities > 0, $payFacilities, 'Turn on Takes payments for at least one facility and keep one payment method enabled.'],
            ['receipt_settings', 'Receipt settings', $receipt, true, $receipt ? 1 : 0, 'Set your business name, address and footer for receipts.'],
            ['booking_resources', 'Bookable resources', $resources > 0, $bookingFacilities > 0, $resources, 'Add the courts, rooms or chairs customers can book.'],
            ['tables', 'Dining tables', $tables > 0, $tableFacilities > 0, $tables, 'Add tables (e.g. T1-T20) for table service.'],
        ];
        $out = [];
        $req = 0;
        $done = 0;
        $missing = [];
        foreach ($steps as [$key, $label, $ok, $required, $count, $hint]) {
            $out[] = ['key' => $key, 'label' => $label, 'done' => (bool) $ok, 'required' => (bool) $required, 'count' => $count, 'hint' => $ok ? null : $hint];
            if ($required) {
                $req++;
                $ok ? $done++ : $missing[] = $key;
            }
        }

        return [
            'percent' => $req === 0 ? 100 : (int) floor($done * 100 / $req), 'complete' => $missing === [], 'steps' => $out, 'missing' => $missing,
            'counts' => ['facilities' => $facilities, 'facilitiesConfigured' => $configured, 'products' => $products, 'productsPriced' => $priced, 'taxRates' => $taxRates, 'staff' => $staff, 'staffWithRoles' => $assigned,
                'customRoles' => $customRoles, 'devices' => $devices, 'kdsStations' => $stations, 'paymentFacilities' => $payFacilities, 'bookingResources' => $resources, 'tables' => $tables],
        ];
    }
}
