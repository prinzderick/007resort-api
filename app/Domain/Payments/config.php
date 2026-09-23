<?php

// Merged as config('payments.*'). Env vars are documented (placeholders only) in .env.example.
return [
    'currency' => 'NGN',

    // Paystack (ADR-0009). The secret key is BOTH the API credential and the webhook HMAC key - never commit it.
    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        'callback_url' => env('PAYSTACK_CALLBACK_URL'),
        'timeout_seconds' => (int) env('PAYSTACK_TIMEOUT_SECONDS', 15),
        // Optional comma-separated source-IP allow-list for webhooks (Paystack publishes its egress IPs). Empty = signature only.
        'webhook_allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('PAYSTACK_WEBHOOK_ALLOWED_IPS', ''))))),
    ],

    // Receipt header/footer (ADR-0011). VAT registration + TIN come from organization_tax_setting (admin-settable), not from here.
    'receipt' => [
        'business_name' => env('RECEIPT_BUSINESS_NAME'),        // defaults to organization.name
        'site_address' => env('RECEIPT_SITE_ADDRESS', ''),      // `site` has no address column yet
        'footer' => env('RECEIPT_FOOTER', 'Thank you for choosing 007 Resort & Spa'),
        'timezone' => env('RECEIPT_TIMEZONE', 'Africa/Lagos'),
        'columns' => (int) env('RECEIPT_COLUMNS', 48),          // 80mm = 48, 58mm = 32
    ],

    // A payment with NO cash session (non-cash tender at a facility that does not require one) can still be reversed for this long.
    'reversal_window_hours' => (int) env('PAYMENT_REVERSAL_WINDOW_HOURS', 24),
];
