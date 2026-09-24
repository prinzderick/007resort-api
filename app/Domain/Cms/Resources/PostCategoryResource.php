<?php

namespace App\Domain\Cms\Resources;

use App\Domain\Cms\Support\MediaResolver;
use App\Domain\Cms\Support\Slugs;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\DB;

class PostCategoryResource extends ContentResource
{
    protected array $map = ['slug' => 'slug', 'name' => 'name', 'description' => 'description', 'sortOrder' => 'sort_order'];

    public function key(): string
    {
        return 'post-categories';
    }

    public function table(): string
    {
        return 'cms_post_category';
    }

    public function entity(): string
    {
        return 'post_category';
    }

    public function hasStatus(): bool
    {
        return false;
    }

    public function titleField(): string
    {
        return 'name';
    }

    public function order(): array
    {
        return ['sort_order', 'asc'];
    }

    public function fields(): array
    {
        return ['slug' => ['string', 'max:120', 'regex:'.Slugs::REGEX], 'name' => ['string', 'max:80'], 'description' => ['string', 'max:300'], 'sortOrder' => ['integer', 'between:-100000,100000']];
    }

    public function required(): array
    {
        return ['name'];
    }

    public function guardDelete(object $row): void
    {
        if (DB::table('cms_post')->where('category_id', $row->id)->exists()) {
            throw ApiProblem::conflict('category_in_use', 'Posts still use this category; move or delete them first.');
        }
    }

    public function admin(object $r, MediaResolver $media): array
    {
        return $this->head($r) + [
            'name' => $r->name, 'description' => $r->description, 'sortOrder' => (int) $r->sort_order,
            'postCount' => DB::table('cms_post')->where('category_id', $r->id)->count(),
        ] + $this->tail($r);
    }
}
