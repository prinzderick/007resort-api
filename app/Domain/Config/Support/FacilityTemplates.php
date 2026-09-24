<?php

namespace App\Domain\Config\Support;

/**
 * Starter kits for new facilities (POST /organization/facilities {templateKey}). A template pre-enables capabilities, sets
 * sensible default operating rules and (optionally) creates starter operating points / a KDS station. Everything remains editable
 * afterwards; a template is a starting point, never a runtime type (no code branches on `template_key`, ADR-0008).
 */
final class FacilityTemplates
{
    private const POS = ['POS', 'PAYMENT_ACCEPTANCE', 'RECEIPT_PRINTING'];

    private const APPROVALS = ['approval_threshold_amount' => '5000.0000', 'require_approval_for' => ['order.void', 'order.discount', 'order.comp'], 'require_cash_session' => true];

    /** @return array<string, array<string, mixed>> keyed by template key */
    public static function all(): array
    {
        static $t = null;

        return $t ??= self::build();
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** @return array<string, array<string, mixed>> */
    private static function build(): array
    {
        $svc = ['BOOKING', 'APPOINTMENTS', 'STAFF_ASSIGNMENT', 'INVENTORY', 'MEMBERSHIP', 'MEMBER_DISCOUNTS'];
        $rows = [
            'RESTAURANT' => ['Restaurant', 'A sit-down restaurant: table service, open tabs, food goes to the kitchen.', 'RESTAURANT',
                [...self::POS, 'TABLE_SERVICE', 'OPEN_TAB', 'KITCHEN_ROUTING', 'INVENTORY'],
                self::APPROVALS + ['payment_timing' => 'PAY_BEFORE_LEAVING', 'stock_consumption_timing' => 'SEND'],
                [['MAIN_DINING', 'Main dining', 'TABLE_AREA'], ['COUNTER_1', 'Cash counter', 'COUNTER']], null],
            'BAR' => ['Bar / lounge', 'A bar with table service and tabs; drinks go to a bar station.', 'BAR',
                [...self::POS, 'TABLE_SERVICE', 'OPEN_TAB', 'BAR_ROUTING', 'INVENTORY'],
                self::APPROVALS + ['payment_timing' => 'PAY_BEFORE_LEAVING', 'stock_consumption_timing' => 'SEND'],
                [['LOUNGE', 'Lounge', 'TABLE_AREA'], ['COUNTER_1', 'Bar counter', 'COUNTER']], ['BAR_STATION', 'Bar station', 'BAR']],
            'CLUB' => ['Club', 'A club: bar service, members get discounts, tabs settled on exit.', 'CLUB',
                [...self::POS, 'TABLE_SERVICE', 'OPEN_TAB', 'BAR_ROUTING', 'INVENTORY', 'MEMBERSHIP', 'MEMBER_DISCOUNTS'],
                self::APPROVALS + ['payment_timing' => 'PAY_ON_EXIT', 'stock_consumption_timing' => 'SEND'],
                [['CLUB_FLOOR', 'Club floor', 'TABLE_AREA'], ['COUNTER_1', 'Club counter', 'COUNTER']], ['BAR_STATION', 'Bar station', 'BAR']],
            'SPA_SERVICE' => ['Spa (appointments)', 'Bookable treatments with staff assignment, stock and member discounts.', 'SPA',
                [...self::POS, ...$svc, 'CAPACITY_MANAGEMENT'],
                ['approval_threshold_amount' => '5000.0000', 'require_cash_session' => true, 'hold_ttl_seconds' => 900],
                [['COUNTER_1', 'Spa counter', 'COUNTER'], ['ROOMS', 'Treatment rooms', 'ROOM']], null],
            'SALON' => ['Salon (appointments)', 'Chair-based appointments with staff assignment and stock.', 'SALON',
                [...self::POS, ...$svc],
                ['require_cash_session' => true, 'hold_ttl_seconds' => 900],
                [['COUNTER_1', 'Salon counter', 'COUNTER']], null],
            'RETAIL_STORE' => ['Retail store', 'Barcode-scanned retail sales with stock tracking.', 'RETAIL',
                [...self::POS, 'BARCODE_SALES', 'INVENTORY'],
                ['require_cash_session' => true, 'stock_consumption_timing' => 'SETTLE'],
                [['TILL_1', 'Till 1', 'COUNTER']], null],
            'SPORTS_RESOURCE_GROUP' => ['Sports venue (bookable pitches/courts)', 'Bookable courts and pitches with time slots, capacity and entry validation.', 'SPORTS',
                ['BOOKING', 'TIME_SLOTS', 'CAPACITY_MANAGEMENT', 'TICKET_VALIDATION', 'QR_VALIDATION', 'MEMBERSHIP'],
                ['slot_granularity_minutes' => 60, 'validation_mode' => 'ENTRY', 'hold_ttl_seconds' => 900],
                [['ENTRANCE', 'Entrance', 'GATE']], null],
            'POOL' => ['Pool', 'Ticket/member entry and exit control.', 'POOL',
                ['TICKET_VALIDATION', 'QR_VALIDATION', 'MEMBERSHIP'],
                ['validation_mode' => 'ENTRY_EXIT'],
                [['ENTRANCE', 'Pool entrance', 'GATE']], null],
            'CAFE' => ['Cafe', 'A counter cafe with stock tracking.', 'CAFE',
                [...self::POS, 'INVENTORY'],
                ['require_cash_session' => true, 'stock_consumption_timing' => 'SEND', 'payment_timing' => 'PAY_FIRST'],
                [['COUNTER_1', 'Cafe counter', 'COUNTER']], null],
            'STORE_ROOM' => ['Store room', 'A stock room: receives, transfers and counts stock. No selling.', 'STORE',
                ['INVENTORY'], [],
                [['STORE_WINDOW', 'Issue window', 'STORE_WINDOW']], null],
            'KITCHEN' => ['Kitchen', 'A production kitchen with a KDS station where orders from other facilities are prepared.', 'KITCHEN',
                ['INVENTORY'], ['stock_consumption_timing' => 'SEND'],
                [], ['PASS', 'Kitchen pass', 'KITCHEN']],
        ];

        $out = [];
        foreach ($rows as $key => [$label, $desc, $kind, $caps, $rules, $points, $station]) {
            $out[$key] = [
                'key' => $key, 'label' => $label, 'description' => $desc, 'defaultKind' => $kind, 'capabilities' => $caps, 'operatingRules' => $rules,
                'starterOperatingPoints' => array_map(fn ($p) => ['code' => $p[0], 'name' => $p[1], 'kind' => $p[2]], $points),
                'starterKdsStation' => $station === null ? null : ['code' => $station[0], 'name' => $station[1], 'kind' => $station[2]],
            ];
        }

        return $out;
    }
}
