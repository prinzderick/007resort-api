<?php

namespace App\Support\Sync;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * Which rows each operational event carries to the other node (see RowSnapshot). Called by Outbox::record inside the business
 * transaction, so the snapshot is exactly what was committed with the change. Parents come first in every plan.
 */
final class OpsSnapshotPlans
{
    public const TYPES = [
        'OrderCreated', 'OrderUpdated', 'OrderVoided', 'OrderSettled', 'TabOpened', 'TabSettled',
        'PaymentCompleted', 'OnlinePaymentConfirmed', 'PaymentConfirmed', 'PaymentCollected', 'PaymentRejected', 'PaymentReversed', 'PaymentTerminalChanged',
        'CashSessionOpened', 'CashSessionClosed', 'CashHandoverRecorded',
        'StockReceived', 'StockTransferred', 'StockConsumed', 'StockAdjusted',
        'DeviceRegistered', 'DeviceRevoked',
        'MembershipPurchased', 'MembershipRenewed', 'MembershipStatusChanged', 'MembershipUsageRecorded',
        'CustomerRegistered', 'CustomerEmailVerified', 'AttendanceCorrected', 'StaffClockedIn', 'StaffClockedOut',
    ];

    /** @return list<array{0: string, 1: string, 2: list<string|null>}>|null null = not an operational event */
    public static function plan(string $type, string $entityType, string $entityId): ?array
    {
        return match (true) {
            ! in_array($type, self::TYPES, true) => null,
            str_starts_with($type, 'Order') => self::orders([$entityId]),
            str_starts_with($type, 'Tab') => self::tab($entityId),
            $type === 'PaymentTerminalChanged' => [['payment_terminal', 'id', [$entityId]]],
            str_starts_with($type, 'Payment') || $type === 'OnlinePaymentConfirmed' => self::payment($entityId),
            $type === 'CashHandoverRecorded' => [['cash_handover', 'id', [$entityId]], ['cash_in_hand_entry', 'handover_id', [$entityId]]],
            str_starts_with($type, 'CashSession') => [['cash_session', 'id', [$entityId]], ['cash_movement', 'cash_session_id', [$entityId]]],
            str_starts_with($type, 'Stock') => self::stock($entityType, $entityId),
            str_starts_with($type, 'Device') => [['device', 'id', [$entityId]]],
            str_starts_with($type, 'Membership') => [
                ['membership', 'id', [$entityId]], ['membership_usage', 'membership_id', [$entityId]],
                ['membership_status_history', 'membership_id', [$entityId]], ['member_card', 'membership_id', [$entityId]],
            ],
            str_starts_with($type, 'Customer') => [['customer', 'id', [$entityId]], ['customer_account', 'customer_id', [$entityId]]],
            in_array($type, ['AttendanceCorrected', 'StaffClockedIn', 'StaffClockedOut'], true) => [['attendance_correction', 'staff_id', [$entityId]], ['attendance_day', 'staff_id', [$entityId]]],
            default => null,
        };
    }

    /** @param list<string> $orderIds */
    private static function orders(array $orderIds): array
    {
        $bin = array_map(fn ($i) => Ids::toBinary($i), $orderIds);
        $in = implode(',', array_fill(0, count($bin), '?'));
        $tabs = array_map(fn ($r) => Ids::fromBinary($r->tab_id), DB::select("SELECT DISTINCT tab_id FROM `order` WHERE id IN ($in) AND tab_id IS NOT NULL", $bin));
        $lines = array_map(fn ($r) => Ids::fromBinary($r->id), DB::select("SELECT id FROM order_line WHERE order_id IN ($in)", $bin));

        return [['tab', 'id', $tabs], ['order', 'id', $orderIds], ['order_line', 'order_id', $orderIds], ['line_adjustment', 'order_line_id', $lines], ['line_void', 'order_id', $orderIds]];
    }

    private static function tab(string $tabId): array
    {
        $orders = array_map(fn ($r) => Ids::fromBinary($r->id), DB::select('SELECT id FROM `order` WHERE tab_id = ?', [Ids::toBinary($tabId)]));

        return array_merge([['tab', 'id', [$tabId]]], $orders === [] ? [] : self::orders($orders));
    }

    private static function payment(string $paymentId): array
    {
        $bin = Ids::toBinary($paymentId);
        $p = DB::selectOne('SELECT receipt_id, cash_session_id FROM payment WHERE id = ?', [$bin]);
        $orders = array_map(fn ($r) => Ids::fromBinary($r->order_id), DB::select('SELECT DISTINCT order_id FROM payment_allocation WHERE payment_id = ?', [$bin]));
        $plan = [];
        if ($p?->cash_session_id) {
            $plan[] = ['cash_session', 'id', [Ids::fromBinary($p->cash_session_id)]];
        }
        if ($orders !== []) {
            $plan = array_merge($plan, self::orders($orders));
        }
        if ($p?->receipt_id) {
            $rid = Ids::fromBinary($p->receipt_id);
            $plan[] = ['receipt', 'id', [$rid]];
            $plan[] = ['receipt_order', 'receipt_id', [$rid]];
        }

        return array_merge($plan, [
            ['payment', 'id', [$paymentId]], ['payment_allocation', 'payment_id', [$paymentId]], ['payment_collection', 'payment_id', [$paymentId]],
            ['payment_collection_decision', 'payment_id', [$paymentId]], ['refund', 'payment_id', [$paymentId]], ['reversal', 'payment_id', [$paymentId]],
            ['cash_in_hand_entry', 'payment_id', [$paymentId]],
        ]);
    }

    private static function stock(string $entityType, string $entityId): array
    {
        $bin = Ids::toBinary($entityId);
        $docs = match ($entityType) {
            'PurchaseReceipt' => [['purchase_receipt', 'id', [$entityId]], ['purchase_receipt_line', 'receipt_id', [$entityId]]],
            'StockTransfer' => [['stock_transfer', 'id', [$entityId]], ['stock_transfer_line', 'transfer_id', [$entityId]]],
            'StockAdjustment' => [['stock_adjustment', 'id', [$entityId]], ['stock_adjustment_line', 'adjustment_id', [$entityId]]],
            'StockCount' => [['stock_count', 'id', [$entityId]], ['stock_count_line', 'count_id', [$entityId]]],
            default => [],
        };
        $refs = [$entityId];
        if ($entityType === 'StockCount' && ($adj = DB::selectOne('SELECT adjustment_id FROM stock_count WHERE id = ?', [$bin])?->adjustment_id)) {
            $refs[] = Ids::fromBinary($adj);
            $docs[] = ['stock_adjustment', 'id', [Ids::fromBinary($adj)]];
            $docs[] = ['stock_adjustment_line', 'adjustment_id', [Ids::fromBinary($adj)]];
        }
        $in = implode(',', array_fill(0, count($refs), '?'));
        $items = array_map(fn ($r) => Ids::fromBinary($r->item_id), DB::select("SELECT DISTINCT item_id FROM stock_movement WHERE reference_id IN ($in) OR id = ?", [...array_map(fn ($i) => Ids::toBinary($i), $refs), $bin]));

        return array_merge($docs, [['stock_movement', 'reference_id', $refs], ['stock_movement', 'id', [$entityId]], ['stock_balance', 'item_id', $items]]);
    }
}
