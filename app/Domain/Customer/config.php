<?php

// config('customer.*')
return [
    // Website customers hold their token server-side (BFF), so the access token lives longer than a staff token.
    'access_ttl_minutes' => (int) env('CUSTOMER_ACCESS_TTL_MINUTES', 480),
    'refresh_ttl_days' => (int) env('CUSTOMER_REFRESH_TTL_DAYS', 30),
    'max_failed_logins' => (int) env('CUSTOMER_MAX_FAILED_LOGINS', 5),
    'lockout_minutes' => (int) env('CUSTOMER_LOCKOUT_MINUTES', 15),
    'verify_ttl_minutes' => 30,
    'verify_max_attempts' => 5,
    'reset_ttl_minutes' => 60,
    'password_min_length' => 10,

    // Base URL of the website, used only to build the link in verification / reset emails.
    'web_url' => env('CUSTOMER_WEB_URL', 'http://127.0.0.1:8092'),
    // Optional allow-list of hosts a Paystack callbackUrl may point at (empty = any https/http URL).
    'allowed_callback_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('CUSTOMER_ALLOWED_CALLBACK_HOSTS', ''))))),

    'service_token_grace_hours' => (int) env('SERVICE_TOKEN_GRACE_HOURS', 24),

    'tickets' => ['max_per_order' => 20, 'max_days_ahead' => 60],

    // GET /public/site
    'site' => [
        'name' => env('PUBLIC_SITE_NAME'),
        'contact' => [
            'phone' => env('PUBLIC_CONTACT_PHONE'),
            'email' => env('PUBLIC_CONTACT_EMAIL'),
            'address' => env('PUBLIC_CONTACT_ADDRESS'),
            'mapUrl' => env('PUBLIC_CONTACT_MAP_URL'),
        ],
        'opening_hours' => env('PUBLIC_OPENING_HOURS'),
    ],
];
