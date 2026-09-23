<?php

// Available as config('identity.*'). Module config.php files are auto-merged under the lowercase module name.
return [
    'access_ttl_minutes' => (int) env('IDENTITY_ACCESS_TTL_MINUTES', 15),
    'refresh_ttl_days' => (int) env('IDENTITY_REFRESH_TTL_DAYS', 30),
    'max_failed_logins' => (int) env('IDENTITY_MAX_FAILED_LOGINS', 5),
    'lockout_minutes' => (int) env('IDENTITY_LOCKOUT_MINUTES', 15),
    'step_up_seconds' => 300,
];
