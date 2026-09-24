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

    // Base URL printed on the pre-bill as the customer pay link / QR (rule bill_pay_link_enabled). Empty = no URL.
    'pay_link_base_url' => env('PAY_LINK_BASE_URL'),

    // Waiter collection (docs/WAITER_COLLECTION.md). Facility rules override these defaults.
    'collection' => [
        'expiry_minutes' => (int) env('PAYMENTS_COLLECTION_EXPIRY_MINUTES', 30),
        'handover_max_variance' => env('PAYMENTS_HANDOVER_MAX_VARIANCE', '500'),
        'transfer_account_ttl_minutes' => (int) env('PAYMENTS_TRANSFER_ACCOUNT_TTL_MINUTES', 60),
        // Paystack needs an email; used when the waiter does not capture the customer's.
        'default_customer_email' => env('PAYMENTS_COLLECTION_DEFAULT_EMAIL', 'pay@collect.007resort.invalid'),
    ],

    // Card terminals (docs/WAITER_COLLECTION.md section 5). MANUAL_BANK is always available; the Paystack terminal is a disabled stub.
    'terminals' => [
        'paystack_enabled' => (bool) env('PAYMENTS_TERMINAL_PAYSTACK_ENABLED', false),
    ],

    // A payment with NO cash session (non-cash tender at a facility that does not require one) can still be reversed for this long.
    'reversal_window_hours' => (int) env('PAYMENT_REVERSAL_WINDOW_HOURS', 24),
];
