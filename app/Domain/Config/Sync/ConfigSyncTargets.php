<?php

namespace App\Domain\Config\Sync;

use App\Domain\Config\Services\BookingConfigService;
use App\Domain\Config\Services\OperatingRuleService;
use App\Domain\Sync\Appliers\VersionedTarget;
use App\Domain\Sync\Appliers\VersionedTargets;
use App\Domain\Sync\Support\InboundEvent;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Receive side of every `ConfigurationUpdated` domain the Config module emits (docs/CONFIG_ADMIN_API.md section 13). Each domain is a
 * VersionedTarget: applied only when `entityVersion == local version + 1` (v1 creates the row), stale -> CONFIGURATION_CONFLICT.
 * Simple tables are pure column maps (payload camelCase => column); child rows (capabilities, rules, KDS station, links...) use the `after` hook.
 * Composite-key rows without their own row_version (blackout, product_facility, prep_route_station) are versioned in config_entity_version.
 */
final class ConfigSyncTargets
{
    public static function register(VersionedTargets $t): void
    {
        $cat = 'CONFIGURATION';
        $org = ['organizationId' => ['organization_id', 'uuid']];
        $orgSite = $org + ['siteId' => ['site_id', 'uuid']];

        // ---- facility -------------------------------------------------------------------------------------------------------
        $t->register('facilityFull', self::target('facility_unit', $cat, [], creator: self::createFacility(...)));
        $t->register('facilityDetails', self::target('facility_unit', $cat, [
            'name' => ['name', 'string'], 'kind' => ['kind', 'string'], 'description' => ['description', 'string'], 'timezone' => ['timezone', 'string'], 'sortOrder' => ['sort_order', 'int'],
            'contact' => ['contact', 'json'], 'openingHours' => ['opening_hours', 'json'], 'isActive' => ['is_active', 'bool'], 'parentId' => ['parent_id', 'uuid'],
            'deactivationReason' => ['deactivation_reason', 'string'], 'deletedAt' => ['deleted_at', 'datetime'],
        ]));
        $t->register('facilityCapabilities', self::target('facility_unit', $cat, [], after: self::applyCapabilities(...)));
        $t->register('facilityRules', self::target('facility_unit', $cat, [], after: self::applyRules(...)));
        $t->register('facilityPaymentMethods', self::target('facility_unit', $cat, [], after: self::applyPaymentMethods(...)));

        // ---- operating points / tables -----------------------------------------------------------------------------------------
        $t->register('operatingPoint', self::target('operating_point', $cat, $orgSite + [
            'facilityId' => ['facility_unit_id', 'uuid'], 'code' => ['code', 'string'], 'name' => ['name', 'string'], 'kind' => ['kind', 'string'], 'defaultPrepStationId' => ['default_prep_station_id', 'uuid'], 'active' => ['is_active', 'bool'],
        ], upsert: true, after: self::applyKdsStation(...)));
        $t->register('diningTable', self::target('dining_table', $cat, $orgSite + [
            'facilityId' => ['facility_unit_id', 'uuid'], 'operatingPointId' => ['operating_point_id', 'uuid'], 'label' => ['label', 'string'], 'seats' => ['seats', 'int'], 'active' => ['is_active', 'bool'],
            'sortOrder' => ['sort_order', 'int'], 'mergedIntoId' => ['merge_parent_id', 'uuid'],
        ], upsert: true));

        // ---- catalogue ----------------------------------------------------------------------------------------------------------
        $t->register('productCategory', self::target('product_category', $cat, $org + ['parentId' => ['parent_id', 'uuid'], 'name' => ['name', 'string'], 'sortOrder' => ['sort_order', 'int'], 'active' => ['is_active', 'bool']], upsert: true));
        $t->register('product', self::target('product', $cat, $org + [
            'categoryId' => ['category_id', 'uuid'], 'sku' => ['sku', 'string'], 'name' => ['name', 'string'], 'kind' => ['kind', 'string'], 'taxRateId' => ['tax_rate_id', 'uuid'], 'taxExempt' => ['tax_exempt', 'bool'],
            'prepRouteId' => ['prep_route_id', 'uuid'], 'trackStock' => ['track_stock', 'bool'], 'imageUrl' => ['image_url', 'string'], 'description' => ['description', 'string'], 'barcode' => ['barcode', 'string'],
            'modifiers' => ['modifiers', 'json'], 'isActive' => ['is_active', 'bool'],
        ], upsert: true));
        $t->register('productStockLinks', self::target('product', $cat, [], after: self::applyStockLinks(...)));
        $t->register('priceList', self::target('price_list', $cat, $org + ['name' => ['name', 'string'], 'currency' => ['currency', 'string'], 'isDefault' => ['is_default', 'bool'], 'active' => ['is_active', 'bool']], upsert: true));
        $t->register('price', self::target('price', $cat, [
            'priceListId' => ['price_list_id', 'uuid'], 'productId' => ['product_id', 'uuid'], 'facilityId' => ['facility_unit_id', 'uuid'], 'amount' => ['amount', 'decimal'], 'validFrom' => ['valid_from', 'datetime'],
            'validTo' => ['valid_to', 'datetime'], 'active' => ['is_active', 'bool'],
        ], upsert: true));
        $t->register('taxRate', self::target('tax_rate', $cat, $org + ['code' => ['code', 'string'], 'name' => ['name', 'string'], 'ratePercent' => ['rate_percent', 'decimal'], 'active' => ['is_active', 'bool']], upsert: true));
        $t->register('prepRoute', self::target('prep_route', $cat, $org + ['code' => ['code', 'string'], 'name' => ['name', 'string'], 'kind' => ['kind', 'string']], upsert: true));
        $t->register('productFacility', self::target('config_entity_version', $cat, [], 'version', 'entity_id', creator: self::createVersioned(...), after: self::applyProductFacility(...)));
        $t->register('prepRouteStation', self::target('config_entity_version', $cat, [], 'version', 'entity_id', creator: self::createVersioned(...), after: self::applyPrepRouteStation(...)));

        // ---- tickets, booking, settings -----------------------------------------------------------------------------------------
        $t->register('ticketType', self::target('ticket_type', $cat, $orgSite + [
            'facilityId' => ['facility_unit_id', 'uuid'], 'productId' => ['product_id', 'uuid'], 'code' => ['code', 'string'], 'name' => ['name', 'string'], 'format' => ['format', 'string'], 'validationMode' => ['validation_mode', 'string'],
            'validityKind' => ['validity_kind', 'string'], 'validityMinutes' => ['validity_minutes', 'int'], 'earlyEntryMinutes' => ['early_entry_minutes', 'int'], 'active' => ['is_active', 'bool'],
        ], upsert: true));
        $t->register('bookableResource', self::target('bookable_resource', $cat, $orgSite + [
            'facilityId' => ['facility_unit_id', 'uuid'], 'code' => ['code', 'string'], 'name' => ['name', 'string'], 'mode' => ['mode', 'string'], 'capacity' => ['capacity', 'int'], 'slotMinutes' => ['slot_minutes', 'int'],
            'maxSlotsPerBooking' => ['max_slots_per_booking', 'int'], 'price' => ['price', 'decimal'], 'wholePrice' => ['whole_price', 'decimal'], 'productId' => ['product_id', 'uuid'],
            'allowWholeResource' => ['allow_whole_resource', 'bool'], 'onlineBookable' => ['online_bookable', 'bool'], 'active' => ['is_active', 'bool'], 'offlineStrategy' => ['offline_strategy', 'string'],
            'localReserveUnits' => ['local_reserve_units', 'int'], 'onlineStaleAfterSeconds' => ['online_stale_after_seconds', 'int'],
        ], upsert: true));
        $t->register('resourceSchedule', self::target('bookable_resource', $cat, [], after: self::applySchedule(...)));
        $t->register('resourceRules', self::target('bookable_resource', $cat, [], after: self::applyResourceRules(...)));
        $t->register('blackout', self::target('config_entity_version', $cat, [], 'version', 'entity_id', creator: self::createVersioned(...), after: self::applyBlackout(...)));
        $t->register('receiptSetting', self::target('receipt_setting', $cat, [
            'businessName' => ['business_name', 'string'], 'address' => ['address', 'string'], 'phone' => ['phone', 'string'], 'headerNote' => ['header_note', 'string'], 'footer' => ['footer', 'string'],
            'logoUrl' => ['logo_url', 'string'], 'showTin' => ['show_tin', 'bool'], 'paperColumns' => ['paper_columns', 'int'],
        ] + $org, keyColumn: 'organization_id', upsert: true));
        $t->register('businessProfile', self::target('site', $cat, [
            'siteName' => ['name', 'string'], 'timezone' => ['time_zone', 'string'], 'address' => ['address', 'string'], 'phone' => ['phone', 'string'], 'email' => ['email', 'string'],
        ], after: self::applyOrganizationName(...)));
        // Role permission sets (keyed by the deterministic public id; the BIGINT id is node-local). Permission category: same version check as the staff roster.
        $t->register('rolePermissions', self::target('role', 'PERMISSION', [], keyColumn: 'public_id', creator: self::createRole(...), after: self::applyRole(...)));
    }

    /** Config domains carry snapshot payloads: unmapped keys are ignored, only mapped columns are ever written. */
    private static function target(mixed ...$args): VersionedTarget
    {
        return new VersionedTarget(...$args, lenient: true);
    }

    // ---- creators -----------------------------------------------------------------------------------------------------------------

    private static function createFacility(InboundEvent $e, array $changes): void
    {
        $f = $changes['facility'];
        $id = Ids::toBinary($e->entityId);
        DB::table('facility_unit')->insert([
            'id' => $id, 'organization_id' => Ids::toBinary($changes['organizationId']), 'site_id' => Ids::toBinary($f['siteId']), 'parent_id' => $f['parentId'] ? Ids::toBinary($f['parentId']) : null,
            'code' => $f['code'], 'name' => $f['name'], 'kind' => $f['kind'], 'description' => $f['description'], 'timezone' => $f['timezone'], 'sort_order' => $f['sortOrder'],
            'contact' => $f['contact'] === null ? null : json_encode($f['contact']), 'opening_hours' => $f['openingHours'] === null ? null : json_encode($f['openingHours']),
            'template_key' => $f['templateKey'], 'is_active' => $f['active'] ? 1 : 0, 'deactivated_at' => $f['active'] ? null : now('UTC')->format('Y-m-d H:i:s.u'), 'row_version' => 1,
        ]);
        foreach ($f['capabilities'] as $c) {
            DB::table('facility_capability')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'facility_unit_id' => $id, 'capability_code' => $c, 'is_enabled' => 1]);
        }
        $row = DB::table('facility_unit')->where('id', $id)->first();
        app(OperatingRuleService::class)->writeInitial($row, $changes['operatingRules'] ?? [], $f['capabilities']);
    }

    private static function createVersioned(InboundEvent $e, array $changes): void
    {
        DB::table('config_entity_version')->insert(['entity_id' => Ids::toBinary($e->entityId), 'version' => 1]);
    }

    private static function createRole(InboundEvent $e, array $changes): void
    {
        $code = $changes['code'];
        DB::table('role')->insert(['public_id' => Ids::toBinary($e->entityId), 'code' => $code, 'name' => $changes['name'] ?? $code, 'description' => $changes['description'] ?? null, 'is_system' => 0, 'row_version' => 1]);
        self::applyRole($e, $changes);
    }

    // ---- after hooks ----------------------------------------------------------------------------------------------------------------

    private static function applyCapabilities(InboundEvent $e, array $changes): void
    {
        if (! isset($changes['capabilities'])) {
            return;
        }
        $bin = Ids::toBinary($e->entityId);
        $want = $changes['capabilities'];
        foreach ($want as $code) {
            $row = DB::table('facility_capability')->where('facility_unit_id', $bin)->where('capability_code', $code)->first(['id']);
            $row === null
                ? DB::table('facility_capability')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'facility_unit_id' => $bin, 'capability_code' => $code, 'is_enabled' => 1])
                : DB::table('facility_capability')->where('id', $row->id)->update(['is_enabled' => 1]);
        }
        DB::table('facility_capability')->where('facility_unit_id', $bin)->whereNotIn('capability_code', $want ?: [''])->update(['is_enabled' => 0]);
    }

    private static function applyRules(InboundEvent $e, array $changes): void
    {
        if (! isset($changes['rules'])) {
            return;
        }
        $bin = Ids::toBinary($e->entityId);
        $row = DB::table('facility_unit')->where('id', $bin)->first();
        $enabled = DB::table('facility_capability')->where('facility_unit_id', $bin)->where('is_enabled', 1)->pluck('capability_code')->all();
        app(OperatingRuleService::class)->writeInitial($row, $changes['rules'], $enabled);
    }

    private static function applyPaymentMethods(InboundEvent $e, array $changes): void
    {
        if (! array_key_exists('methods', $changes)) {
            return;
        }
        $bin = Ids::toBinary($e->entityId);
        DB::table('facility_payment_method')->where('facility_unit_id', $bin)->delete();
        foreach (($changes['methods'] ?? []) as $m => $on) {
            DB::table('facility_payment_method')->insert(['facility_unit_id' => $bin, 'method' => $m, 'is_enabled' => $on ? 1 : 0]);
        }
    }

    private static function applyKdsStation(InboundEvent $e, array $changes): void
    {
        $k = $changes['kdsStation'] ?? null;
        if ($k === null) {
            return;
        }
        $id = Ids::toBinary($e->entityId);
        $op = DB::table('operating_point')->where('id', $id)->first();
        $vals = ['prep_route_id' => ! empty($k['prepRouteId']) ? Ids::toBinary($k['prepRouteId']) : null, 'kind' => $k['kind'], 'is_active' => ! empty($k['active']) ? 1 : 0, 'name' => $op->name];
        if (DB::table('kds_station')->where('id', $id)->exists()) {
            DB::table('kds_station')->where('id', $id)->update($vals);

            return;
        }
        DB::table('kds_station')->insert(['id' => $id, 'organization_id' => $op->organization_id, 'site_id' => $op->site_id, 'facility_unit_id' => $op->facility_unit_id, 'code' => $op->code, 'row_version' => 1] + $vals);
        DB::table('prep_ticket_counter')->insertOrIgnore(['station_id' => $id]);
    }

    private static function applyStockLinks(InboundEvent $e, array $changes): void
    {
        $bin = Ids::toBinary($e->entityId);
        DB::table('product_stock_link')->where('product_id', $bin)->delete();
        foreach ($changes['links'] ?? [] as $l) {
            DB::table('product_stock_link')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'product_id' => $bin, 'stock_item_id' => Ids::toBinary($l['stockItemId']), 'quantity_per_unit' => $l['quantityPerUnit']]);
        }
    }

    private static function applyProductFacility(InboundEvent $e, array $changes): void
    {
        if (! isset($changes['productId'], $changes['facilityId'])) {
            return;
        }
        $p = Ids::toBinary($changes['productId']);
        $f = Ids::toBinary($changes['facilityId']);
        $vals = ['is_available' => ! empty($changes['available']) ? 1 : 0, 'unavailable_reason' => ! empty($changes['available']) ? null : ($changes['reason'] ?? 'MANUALLY_DISABLED')];
        foreach (['kdsStationId' => 'kds_station_id'] as $k => $col) {
            if (array_key_exists($k, $changes)) {
                $vals[$col] = $changes[$k] === null ? null : Ids::toBinary($changes[$k]);
            }
        }
        if (array_key_exists('sortOrder', $changes)) {
            $vals['sort_order'] = (int) $changes['sortOrder'];
        }
        DB::table('product_facility')->where('product_id', $p)->where('facility_unit_id', $f)->exists()
            ? DB::table('product_facility')->where('product_id', $p)->where('facility_unit_id', $f)->update($vals)
            : DB::table('product_facility')->insert(['product_id' => $p, 'facility_unit_id' => $f] + $vals);
    }

    private static function applyPrepRouteStation(InboundEvent $e, array $changes): void
    {
        $f = Ids::toBinary($changes['facilityId']);
        $r = Ids::toBinary($changes['prepRouteId']);
        if (($changes['kdsStationId'] ?? null) === null) {
            DB::table('prep_route_station')->where('facility_unit_id', $f)->where('prep_route_id', $r)->delete();

            return;
        }
        DB::table('prep_route_station')->updateOrInsert(['facility_unit_id' => $f, 'prep_route_id' => $r], ['kds_station_id' => Ids::toBinary($changes['kdsStationId'])]);
    }

    private static function applySchedule(InboundEvent $e, array $changes): void
    {
        if (! isset($changes['windows'])) {
            return;
        }
        $bin = Ids::toBinary($e->entityId);
        DB::table('availability_schedule')->where('resource_id', $bin)->delete();
        foreach ($changes['windows'] as $w) {
            DB::table('availability_schedule')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'resource_id' => $bin, 'day_of_week' => $w['dayOfWeek'], 'open_time' => $w['open'].':00',
                'close_time' => $w['close'] === '24:00' ? '23:59:59' : $w['close'].':00', 'valid_from' => $w['validFrom'] ?? null, 'valid_to' => $w['validTo'] ?? null]);
        }
    }

    private static function applyResourceRules(InboundEvent $e, array $changes): void
    {
        if (! isset($changes['rules'])) {
            return;
        }
        $bin = Ids::toBinary($e->entityId);
        $vals = [];
        foreach (BookingConfigService::RULES as $key => [$col]) {
            $vals[$col] = $changes['rules'][$key] ?? null;
        }
        $existing = DB::table('booking_rule')->where('resource_id', $bin)->first(['id']);
        if (array_filter($vals, fn ($v) => $v !== null) === []) {
            $existing && DB::table('booking_rule')->where('id', $existing->id)->delete();

            return;
        }
        if ($existing) {
            DB::table('booking_rule')->where('id', $existing->id)->update($vals);
        } else {
            $org = DB::table('bookable_resource')->where('id', $bin)->value('organization_id');
            DB::table('booking_rule')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'organization_id' => $org, 'resource_id' => $bin] + $vals);
        }
    }

    private static function applyBlackout(InboundEvent $e, array $changes): void
    {
        $id = Ids::toBinary($e->entityId);
        if (! empty($changes['deleted'])) {
            DB::table('blackout')->where('id', $id)->delete();

            return;
        }
        if (! isset($changes['start']) || DB::table('blackout')->where('id', $id)->exists()) {
            return;
        }
        $utc = fn (string $iso) => CarbonImmutable::parse($iso)->utc()->format('Y-m-d H:i:s.u');
        DB::table('blackout')->insert(['id' => $id, 'organization_id' => Ids::toBinary($changes['organizationId']), 'resource_id' => ! empty($changes['resourceId']) ? Ids::toBinary($changes['resourceId']) : null,
            'facility_unit_id' => ! empty($changes['facilityId']) ? Ids::toBinary($changes['facilityId']) : null, 'starts_at' => $utc($changes['start']), 'ends_at' => $utc($changes['end']), 'reason' => $changes['reason'] ?? null]);
    }

    private static function applyOrganizationName(InboundEvent $e, array $changes): void
    {
        if (! isset($changes['organizationName'])) {
            return;
        }
        $org = DB::table('site')->where('id', Ids::toBinary($e->entityId))->value('organization_id');
        DB::table('organization')->where('id', $org)->update(['name' => $changes['organizationName'], 'row_version' => DB::raw('row_version + 1')]);
    }

    private static function applyRole(InboundEvent $e, array $changes): void
    {
        $role = DB::table('role')->where('public_id', Ids::toBinary($e->entityId))->first();
        if ($role === null) {
            return;
        }
        if (! empty($changes['deleted'])) {
            DB::table('role_permission')->where('role_id', $role->id)->delete();
            DB::table('role')->where('id', $role->id)->delete();

            return;
        }
        $set = array_intersect_key($changes, array_flip(['name', 'description']));
        $set === [] || DB::table('role')->where('id', $role->id)->update($set);
        if (isset($changes['permissions'])) {
            DB::table('role_permission')->where('role_id', $role->id)->delete();
            foreach ($changes['permissions'] as $code => $needsApproval) {
                $pid = DB::table('permission')->where('code', $code)->value('id');
                $pid === null || DB::table('role_permission')->insert(['role_id' => $role->id, 'permission_id' => $pid, 'requires_approval' => $needsApproval ? 1 : 0]);
            }
        }
    }
}
