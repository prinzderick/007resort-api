<?php

/*
 * config('booking.*'). Per-resource / per-facility `booking_rule` rows override these defaults.
 */
return [
    // Property-local time zone: opening hours and slot grids are defined in local time, stored in UTC.
    'timezone' => env('BOOKING_TIMEZONE', 'Africa/Lagos'),

    // Opening hours used when a resource has no availability_schedule rows (local time, every day).
    'default_hours' => ['open' => '06:00', 'close' => '22:00'],

    'defaults' => [
        'hold_ttl_seconds' => 600,
        'min_notice_minutes' => 0,
        'max_advance_days' => 90,
        'cancel_cutoff_minutes' => 120,   // free cancellation until this long before start
        'cancel_fee_percent' => '0',      // fee (of total) when cancelling inside the cutoff (0 = not allowed cheaper: see BookingService)
        'reschedule_cutoff_minutes' => 120,
        'max_reschedules' => 2,
        'early_entry_minutes' => 15,      // ticket becomes valid this long before the slot starts
        'late_grace_minutes' => 0,
    ],

    // ---- Booking Authority (architecture/sync/booking-authority-and-offline-allocation.md) ----
    // false = single-node MVP: this Local node IS the authority for all units (no Cloud deployed yet).
    // true  = two-node: Cloud decides while reachable; offline the resource's strategy (A/B/C) applies.
    'cloud_enabled' => (bool) env('BOOKING_CLOUD_ENABLED', false),

    // How `POST /bookings/{id}/confirm {tenders}` takes payment:
    //   'auto'     = the real Orders+Payments gateway when those modules exist; else 'unlinked' (dev only; production refuses with 501)
    //   'unlinked' = trust the tenders as stated (recorded on the booking + outbox only; NO payment ledger, DEV/DEMO ONLY)
    'payment_gateway' => env('BOOKING_PAYMENT_GATEWAY', 'auto'),

    'sweeper' => ['batch' => 500],
];
