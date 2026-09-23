<?php

namespace App\Domain\Reporting\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Reports read tables owned by OTHER modules (Orders, Payments, Ticketing ...) that may not exist on a given deployment/branch yet.
 * Every query class asks here first and degrades to zeros + an `availability` flag instead of failing.
 */
final class SourceCatalog
{
    /** @var array<string, bool> */
    private static array $cache = [];

    public static function has(string $table, string ...$columns): bool
    {
        $key = $table.'|'.implode(',', $columns);

        return self::$cache[$key] ??= Schema::hasTable($table) && ($columns === [] || Schema::hasColumns($table, $columns));
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    /** @return array<string, bool> which upstream sources this build of the report could use */
    public static function availability(array $sources): array
    {
        return array_map(fn (array $cols) => self::has(...$cols), $sources);
    }
}
