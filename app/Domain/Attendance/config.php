<?php

// config('attendance.*')
return [
    // Punches by the same person on the same terminal closer together than this are one physical tap (double-tap debounce).
    'debounce_seconds' => (int) env('ATTENDANCE_DEBOUNCE_SECONDS', 120),
    // ZKTeco ADMS (/iclock/*) cannot send custom headers, so it authenticates by registered serial number only.
    // Restrict it to the terminal VLAN/LAN: comma-separated CIDRs, empty = allow any source.
    'iclock_allowed_cidrs' => array_values(array_filter(array_map('trim', explode(',', (string) env('ATTENDANCE_ICLOCK_ALLOWED_CIDRS', ''))))),
    'max_punches_per_request' => 2000,
];
