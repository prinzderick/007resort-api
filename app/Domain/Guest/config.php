<?php

// config('guest.*') - docs/GUEST_CHECKOUT.md
return [
    'enabled' => filter_var(env('GUEST_CHECKOUT_ENABLED', true), FILTER_VALIDATE_BOOL),
    'access_ttl_days' => (int) env('GUEST_ACCESS_TTL_DAYS', 90),
    'max_active_tokens' => 5,
    'max_active_holds' => (int) env('GUEST_MAX_ACTIVE_HOLDS', 3),
    'allow_international_phones' => filter_var(env('GUEST_ALLOW_INTERNATIONAL_PHONES', true), FILTER_VALIDATE_BOOL),
    'web_url' => env('GUEST_WEB_URL', env('CUSTOMER_WEB_URL', 'http://127.0.0.1:8092')),
    'turnstile_secret' => env('GUEST_TURNSTILE_SECRET'),
    'turnstile_url' => env('GUEST_TURNSTILE_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
    'rate' => [
        'create_per_ip_hour' => (int) env('GUEST_RATE_CREATE_PER_IP_HOUR', 20),
        'create_per_email_hour' => (int) env('GUEST_RATE_CREATE_PER_EMAIL_HOUR', 10),
        'create_per_phone_hour' => (int) env('GUEST_RATE_CREATE_PER_PHONE_HOUR', 10),
        'lookup_per_ip_15min' => (int) env('GUEST_RATE_LOOKUP_PER_IP_15MIN', 10),
        'lookup_per_reference_15min' => (int) env('GUEST_RATE_LOOKUP_PER_REFERENCE_15MIN', 5),
        'lookup_per_contact_15min' => (int) env('GUEST_RATE_LOOKUP_PER_CONTACT_15MIN', 5),
        'resend_per_order_hour' => (int) env('GUEST_RATE_RESEND_PER_ORDER_HOUR', 3),
    ],
    'message_max_attempts' => 5,
];
