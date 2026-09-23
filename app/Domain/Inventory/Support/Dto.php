<?php

namespace App\Domain\Inventory\Support;

use App\Support\Ids;
use Carbon\CarbonImmutable;

/** JSON shapes (camelCase, ISO-8601 UTC) for the module's rows. */
final class Dto
{
    public static function time(?string $mysql): ?string
    {
        return $mysql === null ? null : CarbonImmutable::parse($mysql, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function item(object $r): array
    {
        return [
            'id' => Ids::fromBinary($r->id), 'sku' => $r->sku, 'name' => $r->name, 'unit' => $r->unit, 'category' => $r->category,
            'reorderLevel' => Qty::normalize($r->reorder_level), 'active' => (bool) $r->is_active, 'rowVersion' => (int) $r->row_version,
        ];
    }

    public static function location(object $r): array
    {
        return [
            'id' => Ids::fromBinary($r->id), 'facilityId' => $r->facility_unit_id ? Ids::fromBinary($r->facility_unit_id) : null, 'name' => $r->name,
            'kind' => $r->kind, 'allowNegative' => (bool) $r->allow_negative, 'isSaleDefault' => (bool) $r->is_sale_default,
            'active' => (bool) $r->is_active, 'rowVersion' => (int) $r->row_version,
        ];
    }

    public static function supplier(object $r): array
    {
        return [
            'id' => Ids::fromBinary($r->id), 'name' => $r->name, 'contactName' => $r->contact_name, 'phone' => $r->phone, 'email' => $r->email,
            'address' => $r->address, 'active' => (bool) $r->is_active, 'rowVersion' => (int) $r->row_version,
        ];
    }

    public static function movement(object $r): array
    {
        return [
            'id' => Ids::fromBinary($r->id), 'itemId' => Ids::fromBinary($r->item_id), 'locationId' => Ids::fromBinary($r->location_id),
            'counterpartLocationId' => $r->counterpart_location_id ? Ids::fromBinary($r->counterpart_location_id) : null,
            'kind' => MovementDocument::kindOf($r->reason), 'reason' => $r->reason, 'quantityDelta' => Qty::normalize($r->qty_delta),
            'balanceAfter' => Qty::normalize($r->balance_after), 'referenceType' => $r->reference_type, 'referenceId' => Ids::fromBinary($r->reference_id),
            'unitCost' => $r->unit_cost === null ? null : ['amount' => $r->unit_cost, 'currency' => 'NGN'], 'note' => $r->note,
            'actorStaffId' => $r->actor_staff_id ? Ids::fromBinary($r->actor_staff_id) : null, 'createdAt' => self::time($r->created_at),
        ];
    }
}
