<?php

namespace App\Support;

/**
 * Per-request identity context, populated by the auth middleware (staff bearer token and/or
 * device token) and read by Audit / Outbox / services so they don't need the actor passed
 * everywhere. Stored on the request attributes (isolated per request, Octane-safe).
 * Outside an HTTP request (queue jobs, artisan) everything is null -> pass ids explicitly.
 */
final class RequestContext
{
    public const STAFF_ID = 'r007.staff_id';

    public const ACCOUNT_ID = 'r007.account_id';

    public const SESSION_ID = 'r007.session_id';

    public const ORGANIZATION_ID = 'r007.organization_id';

    public const SITE_ID = 'r007.site_id';

    public const DEVICE_ID = 'r007.device_id';

    public static function set(string $key, ?string $value): void
    {
        request()->attributes->set($key, $value);
    }

    public static function get(string $key): ?string
    {
        $v = request()->attributes->get($key);

        return is_string($v) && $v !== '' ? $v : null;
    }

    public static function staffId(): ?string
    {
        return self::get(self::STAFF_ID);
    }

    public static function accountId(): ?string
    {
        return self::get(self::ACCOUNT_ID);
    }

    public static function sessionId(): ?string
    {
        return self::get(self::SESSION_ID);
    }

    public static function organizationId(): ?string
    {
        return self::get(self::ORGANIZATION_ID);
    }

    public static function siteId(): ?string
    {
        return self::get(self::SITE_ID);
    }

    public static function deviceId(): ?string
    {
        return self::get(self::DEVICE_ID);
    }
}
