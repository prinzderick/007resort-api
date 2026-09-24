<?php

namespace App\Domain\Cms\Resources;

use App\Support\Http\ApiProblem;

final class ResourceRegistry
{
    /** @var array<string, ContentResource> */
    private static array $cache = [];

    public static function get(string $key): ContentResource
    {
        return self::$cache[$key] ??= match ($key) {
            'pages' => new PageResource,
            'posts' => new PostResource,
            'post-categories' => new PostCategoryResource,
            'events' => new EventResource,
            'gallery/albums' => new AlbumResource,
            'home-sections' => new HomeSectionResource,
            default => throw ApiProblem::notFound(),
        };
    }
}
