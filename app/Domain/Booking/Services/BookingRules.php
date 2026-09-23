<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Models\BookableResource;
use App\Domain\Booking\Models\BookingRule;

/**
 * Effective booking rules for a resource: resource rule > facility rule > config('booking.defaults').
 * Rules are DATA (architecture/10 §1), never code. Each key resolves independently (a resource rule may
 * override only the hold TTL and inherit the rest).
 */
final class BookingRules
{
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    /** @return array{hold_ttl_seconds: int, min_notice_minutes: int, max_advance_days: int, cancel_cutoff_minutes: int, cancel_fee_percent: string, reschedule_cutoff_minutes: int, max_reschedules: int, early_entry_minutes: int} */
    public function for(BookableResource $resource): array
    {
        if (isset($this->cache[$resource->id])) {
            return $this->cache[$resource->id];
        }
        $resolved = config('booking.defaults');
        $facilityRule = BookingRule::query()->where('facility_unit_id', $resource->facility_unit_id)->first();
        $resourceRule = BookingRule::query()->where('resource_id', $resource->id)->first();
        foreach ([$facilityRule, $resourceRule] as $rule) {
            if ($rule === null) {
                continue;
            }
            foreach (array_keys($resolved) as $key) {
                if (array_key_exists($key, $rule->getAttributes()) && $rule->getAttribute($key) !== null) {
                    $resolved[$key] = $rule->getAttribute($key);
                }
            }
        }
        $resolved['cancel_fee_percent'] = (string) $resolved['cancel_fee_percent'];

        return $this->cache[$resource->id] = $resolved;
    }

    public function forget(): void
    {
        $this->cache = [];
    }
}
