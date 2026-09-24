<?php

// config('reporting.*')
return [
    // On the Cloud node, Local-originated figures are flagged stale when the site's last successful sync is older than this.
    'stale_after_seconds' => (int) env('REPORTING_STALE_AFTER_SECONDS', 300),
    // Fixed offset used ONLY by the SQL views (MySQL time-zone tables are often not loaded). Africa/Lagos has no DST.
    'view_utc_offset' => env('REPORTING_VIEW_UTC_OFFSET', '+01:00'),
];
