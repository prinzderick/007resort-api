<?php

return [
    // A counted line whose |variance| exceeds this % of the expected quantity (or any variance when expected is 0)
    // is NOT posted directly: it becomes a PENDING_APPROVAL COUNT_VARIANCE adjustment (unless the poster holds
    // inventory.adjustment.approve).
    'count_variance_threshold_pct' => env('INVENTORY_COUNT_VARIANCE_PCT', '5'),

    // Facility rule `stock_consumption_timing` (SEND | SETTLE) default when a facility has no explicit rule.
    'default_consumption_timing' => env('INVENTORY_DEFAULT_CONSUMPTION_TIMING', 'SEND'),

    // Nightly `r007:inventory:reconcile` schedule (Africa/Lagos).
    'reconcile_at' => env('INVENTORY_RECONCILE_AT', '02:30'),
];
