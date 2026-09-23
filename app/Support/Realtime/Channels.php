<?php

namespace App\Support\Realtime;

use App\Support\RequestContext;

/**
 * Registry of private-channel authorization rules for POST /api/v1/broadcasting/auth (contract api/realtime.md §2).
 * Modules register their channels from their service provider's boot():
 *
 *   Channels::define('kds.station.{stationId}', fn (string $stationId) => app(...)->canView(RequestContext::staffId(), $stationId));
 *
 * The closure receives the pattern's parameters and returns bool; use RequestContext::staffId()/deviceId() for the caller.
 */
final class Channels
{
    /** @var array<string, callable> pattern => authorizer */
    private static array $rules = [];

    public static function define(string $pattern, callable $authorizer): void
    {
        self::$rules[$pattern] = $authorizer;
    }

    /** @return bool|null true/false from the matching rule; null when no rule matches (unknown channel => deny) */
    public static function authorize(string $channel): ?bool
    {
        foreach (self::$rules as $pattern => $authorizer) {
            $regex = '#^'.preg_replace('/\\\{(\w+)\\\}/', '(?P<$1>[^.]+)', preg_quote($pattern, '#')).'$#';
            if (preg_match($regex, $channel, $m)) {
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);

                return (bool) $authorizer(...array_values($params));
            }
        }

        return null;
    }

    /** @return list<string> registered patterns */
    public static function patterns(): array
    {
        return array_keys(self::$rules);
    }

    public static function flush(): void
    {
        self::$rules = [];
    }

    public static function callerStaffId(): ?string
    {
        return RequestContext::staffId();
    }
}
