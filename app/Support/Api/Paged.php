<?php

namespace App\Support\Api;

use App\Support\Http\CursorPage;

/** Contract list envelope: `{ items, nextCursor }` (api/README.md "Conventions"), built from the foundation CursorPage. */
final class Paged
{
    /** @return array{items: list<mixed>, nextCursor: ?string} */
    public static function envelope(CursorPage $page, callable $map): array
    {
        return $page->toArray($map);
    }
}
