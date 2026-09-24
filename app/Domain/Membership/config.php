<?php

// config('membership.*')
return [
    // While the Payments module is not wired, a reception sale that carries tenders equal to the plan price is
    // activated immediately (staff-attested; payment_reference records the tenders). Set false to force every
    // sale through PaymentCaptured -> MembershipService::activateOnPayment().
    'trust_reception_tenders' => (bool) env('MEMBERSHIP_TRUST_RECEPTION_TENDERS', true),
    'max_guests_per_visit' => 20,
    // Scheduler cadence (minutes) for the lifecycle command.
    'lifecycle_every_minutes' => (int) env('MEMBERSHIP_LIFECYCLE_EVERY_MINUTES', 10),
];
