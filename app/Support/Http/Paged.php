<?php

namespace App\Support\Http;

/**
 * Contract-shaped page (`items` + `nextCursor`, api/openapi/v1.yaml) that also keeps the foundation's `data` + `page`
 * members so both conventions work until the two are unified.
 */
final class Paged
{
    public static function of(CursorPage $page, callable $map): array
    {
        $arr = $page->toArray($map);

        return ['items' => $arr['data'], 'nextCursor' => $arr['page']['nextCursor']] + $arr;
    }
}
