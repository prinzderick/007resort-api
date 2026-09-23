<?php

namespace App\Support\Http;

/** Thin helper: contract envelope `{ items, nextCursor }` for a CursorPage. */
final class Paged
{
    /** @return array{items: list<mixed>, nextCursor: ?string} */
    public static function of(CursorPage $page, callable $map): array
    {
        return $page->toArray($map);
    }
}
