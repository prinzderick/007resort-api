<?php

// config('cms.*')
return [
    'timezone' => env('CMS_TIMEZONE', 'Africa/Lagos'),

    'media' => [
        // Any Laravel filesystem disk (default `public`: storage/app/public/cms/... served through the `public/storage` symlink).
        'disk' => env('CMS_MEDIA_DISK', 'public'),
        // Optional absolute base URL that replaces the disk URL (CDN, or the origin node's media host on the other node).
        'url' => env('CMS_MEDIA_URL'),
    ],

    // Base URL of the public website (links in confirmation emails). Falls back to the customer module's setting.
    'web_url' => env('CMS_WEB_URL', env('CUSTOMER_WEB_URL', 'http://127.0.0.1:8092')),

    'subscribers' => [
        'confirm_ttl_hours' => 168,
        'resend_after_minutes' => 2,
        'default_consent_text' => 'I agree to receive news and offers from 007 Resort & Spa by email and can unsubscribe at any time.',
        // Throwaway-mailbox providers: accepted silently (the response never differs) but never stored or emailed.
        'disposable_domains' => ['mailinator.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com', 'temp-mail.org', 'yopmail.com', 'trashmail.com', 'sharklasers.com', 'getnada.com', 'dispostable.com', 'throwawaymail.com', 'maildrop.cc'],
    ],

    // Outbox events CmsContentPublished / CmsMediaUploaded (docs/CMS_API.md section 7). Off until the peer has appliers.
    'sync' => ['emit' => (bool) env('CMS_SYNC_EMIT', false)],

    'cache_seconds' => 60,

    // Demo seeder photo folder (manifest.json + images); default database/seeders/stock.
    'stock_dir' => env('CMS_STOCK_DIR'),
];
