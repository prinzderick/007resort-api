<?php

namespace App\Domain\Config\Support;

/**
 * Human-facing metadata for the fixed `capability_type` catalogue (architecture/05 §2): label, group, dependencies.
 * `requires` = every listed capability must be enabled at the facility. Dependencies are enforced on capabilities that are
 * NEWLY enabled or that a remaining capability depends on being disabled (legacy seeded facilities that predate a rule are
 * never rejected for an edit that does not touch the offending capability).
 */
final class CapabilityCatalogue
{
    /** @return array<string, array{label: string, group: string, description: string, requires: list<string>}> */
    public static function all(): array
    {
        return array_map(fn ($c) => ['label' => $c[0], 'group' => $c[1], 'description' => $c[2], 'requires' => $c[3]], self::raw());
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: list<string>}> */
    private static function raw(): array
    {
        return [
            'POS' => ['Point of sale', 'Selling', 'Staff can ring up orders here (tablets, POS terminals).', []],
            'TABLE_SERVICE' => ['Table service', 'Selling', 'Orders are taken per dining table.', ['POS']],
            'OPEN_TAB' => ['Open tabs', 'Selling', 'Orders accumulate on a tab that is settled later.', ['POS']],
            'KITCHEN_ROUTING' => ['Kitchen routing', 'Selling', 'Food lines are sent to a kitchen KDS station.', ['POS']],
            'BAR_ROUTING' => ['Bar routing', 'Selling', 'Drink lines are sent to a bar KDS station.', ['POS']],
            'BARCODE_SALES' => ['Barcode sales', 'Selling', 'Products are sold by scanning barcodes.', ['POS']],
            'PAYMENT_ACCEPTANCE' => ['Takes payments here', 'Payments', 'Cash/card/transfer payments are accepted at this facility.', []],
            'RECEIPT_PRINTING' => ['Receipt printing', 'Payments', 'Customer receipts are printed here.', []],
            'TICKETING' => ['Ticket sales', 'Tickets & access', 'Tickets/entitlements are issued here.', []],
            'TICKET_VALIDATION' => ['Ticket validation', 'Tickets & access', 'Tickets and entitlements are checked/redeemed here.', []],
            'QR_VALIDATION' => ['QR validation', 'Tickets & access', 'QR codes are scanned here.', ['TICKET_VALIDATION']],
            'BOOKING' => ['Bookings', 'Booking', 'Bookable resources (courts, rooms, chairs) with slots.', []],
            'APPOINTMENTS' => ['Appointments', 'Booking', 'Appointment-style scheduling (spa, salon).', ['BOOKING']],
            'TIME_SLOTS' => ['Time slots', 'Booking', 'Fixed time-slot scheduling.', ['BOOKING']],
            'CAPACITY_MANAGEMENT' => ['Capacity limits', 'Booking', 'A maximum concurrent occupancy is enforced.', []],
            'STAFF_ASSIGNMENT' => ['Staff assignment', 'Booking', 'Staff are assigned to appointments or sessions.', ['APPOINTMENTS']],
            'INVENTORY' => ['Stock tracking', 'Inventory', 'Stock movements and balances are tracked for this facility.', []],
            'EQUIPMENT_RENTAL' => ['Equipment rental', 'Inventory', 'Returnable rental equipment is issued and tracked.', ['INVENTORY']],
            'MEMBERSHIP' => ['Membership', 'Membership', 'Memberships are accepted or sold here.', []],
            'SUBSCRIPTION_BILLING' => ['Subscription billing', 'Membership', 'Recurring membership billing.', ['MEMBERSHIP']],
            'USAGE_LIMITS' => ['Usage limits', 'Membership', 'Per-period visit limits are enforced.', ['MEMBERSHIP']],
            'MEMBER_DISCOUNTS' => ['Member discounts', 'Membership', 'Member pricing/discounts apply here.', ['MEMBERSHIP']],
        ];
    }

    /** @return list<string> */
    public static function requires(string $code): array
    {
        return self::all()[$code]['requires'] ?? [];
    }

    public static function exists(string $code): bool
    {
        return isset(self::raw()[$code]);
    }
}
