<?php

namespace App\Domain\Cms\Support;

use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Slugs
{
    public const REGEX = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** Generate a unique slug from a title (`-2`, `-3` on collision). */
    public static function generate(string $table, string $title, ?string $excludeId = null): string
    {
        $base = Str::slug(Str::limit($title, 100, ''), '-') ?: 'item';
        $slug = $base;
        for ($i = 2; self::taken($table, $slug, $excludeId); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    public static function assertFree(string $table, string $slug, ?string $excludeId = null): void
    {
        if (self::taken($table, $slug, $excludeId)) {
            throw ApiProblem::conflict('slug_taken', "The slug '{$slug}' is already in use.", ['errors' => ['slug' => ['This slug is already in use.']]]);
        }
    }

    public static function taken(string $table, string $slug, ?string $excludeId = null): bool
    {
        $q = DB::table($table)->where('slug', $slug);
        if ($excludeId !== null) {
            $q->where('id', '!=', Rows::bin($excludeId));
        }

        return $q->exists();
    }
}
