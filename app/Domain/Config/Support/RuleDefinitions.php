<?php

namespace App\Domain\Config\Support;

use App\Support\Ids;
use App\Support\Money\Money;

/**
 * The typed operating-rule catalogue (ADR-0008 "typed operating_rule rows"): every rule an admin can set on a facility is
 * defined here (key, label, plain-language description, type, allowed values, default, unit, capability, danger level) and every
 * PUT /facilities/{id}/operating-rules value is validated against its definition (422 field errors).
 *
 * Types: enum | multi_enum | number | bool | duration | money | facility
 *  - money: decimal string "5000.0000" (never a JSON number); duration: integer in `unit`; number: JSON number (`integer` flag)
 *  - facility: UUID of a facility (validated by the service)
 * store: operating_rule (default; string value in operating_rule.rule_value) | booking_rule (facility-scoped `booking_rule` row, the
 *  columns the Booking module reads) | resources (written through to every active bookable_resource of the facility).
 * enforcement: server = a server module reads it; client = exposed to apps via GET /facilities/{id}/capabilities (clients enforce);
 *  planned = stored + audited + synced, no runtime consumer yet (the UI should show a "not enforced yet" hint).
 */
final class RuleDefinitions
{
    public const DANGER = ['low', 'medium', 'high'];

    /** @return array<string, array<string, mixed>> keyed by rule key */
    public static function all(): array
    {
        static $defs = null;

        return $defs ??= self::build();
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** camelCase name used in the effective operatingRules object of GET /facilities/{id}/capabilities. */
    public static function camel(string $key): string
    {
        return lcfirst(str_replace('_', '', ucwords($key, '_')));
    }

    /** Default value with runtime config applied. */
    public static function defaultOf(array $def): mixed
    {
        return match ($def['key']) {
            'hold_ttl_seconds' => (int) config('booking.defaults.hold_ttl_seconds', 600),
            'min_notice_minutes' => (int) config('booking.defaults.min_notice_minutes', 0),
            'max_advance_days' => (int) config('booking.defaults.max_advance_days', 90),
            'cancel_cutoff_minutes' => (int) config('booking.defaults.cancel_cutoff_minutes', 120),
            'cancel_fee_percent' => (float) config('booking.defaults.cancel_fee_percent', 0),
            'reschedule_cutoff_minutes' => (int) config('booking.defaults.reschedule_cutoff_minutes', 120),
            'max_reschedules' => (int) config('booking.defaults.max_reschedules', 2),
            'early_entry_minutes' => (int) config('booking.defaults.early_entry_minutes', 15),
            default => $def['default'],
        };
    }

    /**
     * Validate + normalise one API value against its definition.
     *
     * @return array{0: mixed, 1: ?string} [normalised API value, error message or null]. null (reset to default) is handled by the caller.
     */
    public static function normalize(array $def, mixed $value): array
    {
        switch ($def['type']) {
            case 'bool':
                if (! is_bool($value)) {
                    return [null, 'Must be true or false.'];
                }

                return [$value, null];

            case 'enum':
                $allowed = array_column($def['allowed'], 'value');
                if (! is_string($value) || ! in_array($value, $allowed, true)) {
                    return [null, 'Must be one of: '.implode(', ', $allowed).'.'];
                }

                return [$value, null];

            case 'multi_enum':
                $allowed = array_column($def['allowed'], 'value');
                if (! is_array($value) || ! array_is_list($value)) {
                    return [null, 'Must be a list of values.'];
                }
                foreach ($value as $v) {
                    if (! is_string($v) || ! in_array($v, $allowed, true)) {
                        return [null, 'Every item must be one of: '.implode(', ', $allowed).'.'];
                    }
                }

                return [array_values(array_unique($value)), null];

            case 'number':
            case 'duration':
                if (is_string($value) && is_numeric($value)) {
                    $value = str_contains($value, '.') ? (float) $value : (int) $value;
                }
                if (! is_int($value) && ! (is_float($value) && ! ($def['integer'] ?? false))) {
                    return [null, ($def['integer'] ?? $def['type'] === 'duration') ? 'Must be a whole number.' : 'Must be a number.'];
                }
                if (isset($def['min']) && $value < $def['min']) {
                    return [null, 'Must be at least '.$def['min'].'.'];
                }
                if (isset($def['max']) && $value > $def['max']) {
                    return [null, 'Must be at most '.$def['max'].'.'];
                }

                return [($def['integer'] ?? false) ? (int) $value : (float) $value, null];

            case 'money':
                if (! is_string($value) || ! preg_match('/^\d{1,15}(\.\d{1,4})?$/', $value)) {
                    return [null, 'Must be a non-negative decimal string such as "5000.00".'];
                }

                return [Money::normalize($value), null];

            case 'facility':
                if (! is_string($value) || ! Ids::isUuid($value)) {
                    return [null, 'Must be a facility id.'];
                }

                return [Ids::normalize($value), null];
        }

        return [null, 'Unsupported rule type.'];
    }

    /** API value -> operating_rule.rule_value string. */
    public static function toStored(array $def, mixed $value): string
    {
        return match ($def['type']) {
            'bool' => $value ? 'true' : 'false',
            'multi_enum' => implode(',', $value),
            'number', 'duration' => (string) $value,
            default => (string) $value,
        };
    }

    /** operating_rule.rule_value string -> API value (tolerant of legacy/seeded strings). */
    public static function fromStored(array $def, string $stored): mixed
    {
        return match ($def['type']) {
            'bool' => in_array(strtolower($stored), ['1', 'true', 'yes', 'on'], true),
            'multi_enum' => array_values(array_filter(array_map('trim', explode(',', $stored)), fn ($v) => $v !== '')),
            'number' => ($def['integer'] ?? false) || ctype_digit($stored) ? (int) $stored : (float) $stored,
            'duration' => (int) $stored,
            'money' => Money::isValid($stored) ? Money::normalize($stored) : $stored,
            default => $stored,
        };
    }

    /** @return array<string, array<string, mixed>> */
    private static function build(): array
    {
        $opts = fn (array $pairs) => array_map(fn ($v, $l) => ['value' => $v, 'label' => $l], array_keys($pairs), array_values($pairs));
        $risky = ['order.void' => 'Voiding an order or line', 'order.discount' => 'Discounts', 'order.comp' => 'Complimentary (free) items',
            'order.price_override' => 'Manual price overrides', 'payment.refund' => 'Refunds', 'payment.reversal' => 'Payment reversals'];

        $defs = [
            // ---- Payments & cash ------------------------------------------------------------------------------------------
            ['payment_timing', 'Payment timing', 'When customers pay: after they are served, before anything is prepared, or on a tab settled on exit/at reception. Existing open orders keep working; new orders follow the new timing.',
                'Payments & cash', 'enum', ['POS', 'OPEN_TAB', 'TABLE_SERVICE'], 'PAY_AFTER_SERVICE', null, 'medium', 'server',
                ['allowed' => $opts(['PAY_AFTER_SERVICE' => 'Pay after service', 'PAY_FIRST' => 'Pay first', 'OPEN_TAB' => 'Open tab',
                    'PAY_BEFORE_LEAVING' => 'Pay before leaving (same as after service)', 'PAY_ON_EXIT' => 'Tab, pay on exit', 'PAY_AT_RECEPTION' => 'Tab, pay at reception'])]],
            ['payment_facility_unit_id', 'Payment taken at', 'For facilities without a payment terminal: the facility where the customer pays (usually Main Reception). It must accept payments.',
                'Payments & cash', 'facility', ['POS', 'OPEN_TAB', 'TABLE_SERVICE', 'BOOKING', 'TICKETING', 'TICKET_VALIDATION'], null, null, 'medium', 'server', []],
            ['require_cash_session', 'Require an open cash session', 'Cash payments can only be taken while the cashier has an open cash-drawer session. Turning this off removes a key cash control.',
                'Payments & cash', 'bool', ['PAYMENT_ACCEPTANCE'], true, null, 'high', 'server', []],
            ['allow_offline_payments', 'Payments while offline', 'What the apps may accept when the connection to the property server is down. Card and transfer cannot be verified offline.',
                'Payments & cash', 'enum', ['PAYMENT_ACCEPTANCE'], 'CASH_ONLY', null, 'high', 'client',
                ['allowed' => $opts(['NONE' => 'No payments offline', 'CASH_ONLY' => 'Cash only', 'ALL' => 'All tender types'])]],
            ['allow_offline_orders', 'Orders while offline', 'Staff may keep taking orders when the connection is down; they sync when it returns.',
                'Payments & cash', 'bool', ['POS', 'TABLE_SERVICE'], true, null, 'low', 'client', []],

            // ---- Approvals -------------------------------------------------------------------------------------------------
            ['approval_threshold_amount', 'Approval threshold (discounts and refunds)', 'Amounts above this need a supervisor. 0 means every discount by a non-approver needs approval.',
                'Approvals', 'money', ['POS', 'PAYMENT_ACCEPTANCE'], '0.0000', 'NGN', 'high', 'server', []],
            ['require_approval_for', 'Actions that always need a supervisor', 'These actions always need supervisor approval from anyone who is not a supervisor, whatever the amount.',
                'Approvals', 'multi_enum', ['POS', 'PAYMENT_ACCEPTANCE'], [], null, 'high', 'server', ['allowed' => $opts($risky)]],
            ['approval_threshold_void_amount', 'Void approval threshold', 'Voids of orders worth more than this need a supervisor.',
                'Approvals', 'money', ['POS'], '0.0000', 'NGN', 'high', 'planned', []],
            ['approval_threshold_comp_amount', 'Comp approval threshold', 'Complimentary items worth more than this need a supervisor.',
                'Approvals', 'money', ['POS'], '0.0000', 'NGN', 'high', 'planned', []],
            ['approval_threshold_price_override_amount', 'Price-override approval threshold', 'Manual price changes larger than this need a supervisor.',
                'Approvals', 'money', ['POS'], '0.0000', 'NGN', 'high', 'planned', []],
            ['approval_threshold_refund_amount', 'Refund approval threshold', 'Refunds larger than this need a supervisor.',
                'Approvals', 'money', ['PAYMENT_ACCEPTANCE'], '0.0000', 'NGN', 'high', 'planned', []],

            // ---- Tabs & table service --------------------------------------------------------------------------------------
            ['allow_open_tabs', 'Allow open tabs', 'Customers can run a tab that is settled later.', 'Tabs & table service', 'bool', ['OPEN_TAB'], true, null, 'medium', 'server', []],
            ['tab_max_amount', 'Maximum tab amount', 'A tab cannot grow beyond this amount without settling. 0 means no limit.', 'Tabs & table service', 'money', ['OPEN_TAB'], '0.0000', 'NGN', 'medium', 'planned', []],
            ['tab_require_customer_name', 'Tabs need a customer name', 'Staff must type a customer name when opening a tab.', 'Tabs & table service', 'bool', ['OPEN_TAB'], false, null, 'low', 'planned', []],
            ['require_table_for_orders', 'Orders need a table', 'Every order must be assigned to a dining table.', 'Tabs & table service', 'bool', ['TABLE_SERVICE'], false, null, 'low', 'client', []],

            // ---- Stock -----------------------------------------------------------------------------------------------------
            ['stock_consumption_timing', 'When stock is deducted', 'SEND deducts ingredients/stock when the order is sent to the kitchen or bar; SETTLE waits until the customer pays. SEND is safer for stock accuracy.',
                'Stock', 'enum', ['INVENTORY'], 'SEND', null, 'high', 'server',
                ['allowed' => $opts(['SEND' => 'When the order is sent', 'SETTLE' => 'When the order is paid'])]],

            // ---- Receipts and online -----------------------------------------------------------------------------------------
            ['receipt_auto_print', 'Print receipt automatically', 'The app prints a receipt as soon as a payment is taken.', 'Receipts & online', 'bool', ['RECEIPT_PRINTING'], true, null, 'low', 'client', []],
            ['receipt_copies', 'Receipt copies', 'How many copies the app prints for each receipt.', 'Receipts & online', 'number', ['RECEIPT_PRINTING'], 1, 'copies', 'low', 'client', ['min' => 1, 'max' => 5, 'integer' => true]],
            ['online_orderable', 'Available for online ordering', 'Customers can order from this facility on the website/app.', 'Receipts & online', 'bool', ['POS'], false, null, 'medium', 'planned', []],

            // ---- Tickets & access --------------------------------------------------------------------------------------------
            ['validation_mode', 'Ticket validation mode', 'What a scan at this facility means: ENTRY (one entry check), ENTRY_EXIT (in and out are tracked) or RELEASE_RETURN (equipment is released and returned).',
                'Tickets & access', 'enum', ['TICKET_VALIDATION'], 'ENTRY', null, 'medium', 'client',
                ['allowed' => $opts(['ENTRY' => 'Entry only', 'ENTRY_EXIT' => 'Entry and exit', 'RELEASE_RETURN' => 'Release and return (equipment)'])]],
            ['max_occupancy', 'Maximum occupancy', 'The most people allowed inside at once. Leave unset for no limit.', 'Tickets & access', 'number', ['CAPACITY_MANAGEMENT'], null, 'people', 'medium', 'planned', ['min' => 1, 'max' => 100000, 'integer' => true]],

            // ---- Booking rules (booking_rule table) ----------------------------------------------------------------------------
            ['hold_ttl_seconds', 'Hold time', 'How long a slot is held while the customer completes the booking/payment before it is released.', 'Booking', 'duration', ['BOOKING'], 600, 'seconds', 'medium', 'server', ['min' => 30, 'max' => 86400, 'store' => 'booking_rule', 'mirror' => true]],
            ['min_notice_minutes', 'Minimum notice', 'Bookings must be made at least this long before the start time.', 'Booking', 'duration', ['BOOKING'], 0, 'minutes', 'low', 'server', ['min' => 0, 'max' => 525600, 'store' => 'booking_rule']],
            ['max_advance_days', 'Furthest booking ahead', 'How many days ahead customers can book.', 'Booking', 'duration', ['BOOKING'], 90, 'days', 'low', 'server', ['min' => 0, 'max' => 730, 'store' => 'booking_rule']],
            ['cancel_cutoff_minutes', 'Free-cancellation window', 'Customers can cancel free until this long before the start.', 'Booking', 'duration', ['BOOKING'], 120, 'minutes', 'medium', 'server', ['min' => 0, 'max' => 525600, 'store' => 'booking_rule']],
            ['cancel_fee_percent', 'Late-cancellation fee', 'Percentage of the booking total charged when cancelling inside the free-cancellation window.', 'Booking', 'number', ['BOOKING'], 0, 'percent', 'high', 'server', ['min' => 0, 'max' => 100, 'store' => 'booking_rule']],
            ['reschedule_cutoff_minutes', 'Reschedule cutoff', 'Bookings can be moved until this long before the start.', 'Booking', 'duration', ['BOOKING'], 120, 'minutes', 'low', 'server', ['min' => 0, 'max' => 525600, 'store' => 'booking_rule']],
            ['max_reschedules', 'Maximum reschedules', 'How many times a booking may be moved.', 'Booking', 'number', ['BOOKING'], 2, 'times', 'low', 'server', ['min' => 0, 'max' => 20, 'integer' => true, 'store' => 'booking_rule']],
            ['early_entry_minutes', 'Early entry', 'A booking ticket is valid this long before the slot starts.', 'Booking', 'duration', ['BOOKING'], 15, 'minutes', 'low', 'server', ['min' => 0, 'max' => 240, 'store' => 'booking_rule']],
            ['slot_granularity_minutes', 'Slot length', 'The length of one bookable slot on the grid.', 'Booking', 'number', ['TIME_SLOTS'], 60, 'minutes', 'medium', 'client', ['min' => 5, 'max' => 240, 'integer' => true]],
            ['booking_offline_strategy', 'When the property is offline', 'A: the site keeps taking bookings from a reserved pool of units. B: bookings need the online authority (blocked offline). C: online booking is switched off while the site is offline. Applies to every active bookable resource here.',
                'Booking', 'enum', ['BOOKING'], 'A_OFFLINE_ALLOCATION', null, 'high', 'server',
                ['store' => 'resources', 'allowed' => $opts(['A_OFFLINE_ALLOCATION' => 'A - book offline from a reserved pool', 'B_ONLINE_AUTHORITY_REQUIRED' => 'B - online authority required', 'C_DISABLE_ONLINE' => 'C - disable online booking'])]],
            ['booking_local_reserve_percent', 'Offline reserved share', 'For strategy A: the percentage of each resource capacity the site may allocate while offline (rounded down; 0 = none).',
                'Booking', 'number', ['BOOKING'], 0, 'percent', 'high', 'server', ['min' => 0, 'max' => 100, 'integer' => true, 'store' => 'resources']],
            ['booking_online_stale_after_seconds', 'Connection considered lost after', 'After this long without a heartbeat from the cloud the site treats itself as offline for booking purposes.',
                'Booking', 'duration', ['BOOKING'], 900, 'seconds', 'high', 'server', ['min' => 30, 'max' => 604800, 'store' => 'resources']],
        ];

        $out = [];
        foreach ($defs as [$key, $label, $desc, $group, $type, $caps, $default, $unit, $danger, $enforcement, $extra]) {
            $out[$key] = [
                'key' => $key, 'label' => $label, 'description' => $desc, 'group' => $group, 'type' => $type,
                'capability' => $caps[0], 'capabilities' => $caps, 'default' => $default, 'unit' => $unit, 'danger' => $danger,
                'enforcement' => $enforcement, 'allowed' => $extra['allowed'] ?? null, 'min' => $extra['min'] ?? null, 'max' => $extra['max'] ?? null,
                'integer' => $extra['integer'] ?? in_array($type, ['duration'], true), 'store' => $extra['store'] ?? 'operating_rule', 'mirror' => $extra['mirror'] ?? false,
            ];
        }

        return $out;
    }
}
