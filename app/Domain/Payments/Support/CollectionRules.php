<?php

namespace App\Domain\Payments\Support;

use App\Domain\Orders\Services\BillService;
use App\Domain\Orders\Services\OperatingRules;
use App\Support\Money\Money;

/**
 * Typed operating rules of the waiter-collection workflow (docs/WAITER_COLLECTION.md section 7). Read through Orders' cached
 * {@see OperatingRules} so every module sees the same `operating_rule` rows. Defaults are deliberately conservative
 * (waiters hold no cash unless a facility/staff rule says so).
 */
final class CollectionRules
{
    public const KEYS = [
        'waiter_collection_enabled', 'waiter_cash_holding', 'waiter_cash_in_hand_limit', 'collection_requires_confirmation',
        'pending_collection_expiry_minutes', 'pre_bill_requires_supervisor_if_reopened', 'bill_pay_link_enabled', 'cash_handover_max_variance',
    ];

    public const MANUAL_TENDERS = ['CASH', 'CARD_TERMINAL', 'TRANSFER'];

    public function __construct(private readonly OperatingRules $rules) {}

    /**
     * @return array{collectionEnabled: bool, cashHolding: bool, cashLimit: ?string, requiresConfirmation: list<string>, expiryMinutes: int, reopenNeedsSupervisor: bool, payLinkEnabled: bool, handoverMaxVariance: string}
     */
    public function forFacility(string $facilityId): array
    {
        $r = $this->rules->forFacility($facilityId);
        $raw = $r['raw'];
        $caps = $r['capabilities'];
        $limit = $raw['waiter_cash_in_hand_limit'] ?? $raw['max_cash_in_hand_before_handover'] ?? null;
        $confirm = array_key_exists('collection_requires_confirmation', $raw)
            ? array_values(array_intersect(self::MANUAL_TENDERS, array_map('trim', explode(',', str_replace(['[', ']', '"'], '', $raw['collection_requires_confirmation'])))))
            : self::MANUAL_TENDERS;
        $expiry = isset($raw['pending_collection_expiry_minutes']) && ctype_digit((string) $raw['pending_collection_expiry_minutes']) && (int) $raw['pending_collection_expiry_minutes'] > 0
            ? (int) $raw['pending_collection_expiry_minutes'] : (int) config('payments.collection.expiry_minutes', 30);

        return [
            'collectionEnabled' => array_key_exists('waiter_collection_enabled', $raw) ? BillService::truthy($raw['waiter_collection_enabled']) : in_array('TABLE_SERVICE', $caps, true),
            'cashHolding' => BillService::truthy($raw['waiter_cash_holding'] ?? 'false'),
            'cashLimit' => $limit !== null && Money::isValid($limit) ? Money::normalize($limit) : null,
            'requiresConfirmation' => $confirm,
            'expiryMinutes' => $expiry,
            'reopenNeedsSupervisor' => BillService::truthy($raw['pre_bill_requires_supervisor_if_reopened'] ?? 'true'),
            'payLinkEnabled' => BillService::truthy($raw['bill_pay_link_enabled'] ?? 'false'),
            'handoverMaxVariance' => isset($raw['cash_handover_max_variance']) && Money::isValid($raw['cash_handover_max_variance'])
                ? Money::normalize($raw['cash_handover_max_variance']) : Money::normalize((string) config('payments.collection.handover_max_variance', '500')),
        ];
    }
}
