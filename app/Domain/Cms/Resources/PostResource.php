<?php

namespace App\Domain\Cms\Resources;

use App\Domain\Cms\Support\Markdown;
use App\Domain\Cms\Support\MediaExists;
use App\Domain\Cms\Support\MediaResolver;
use App\Domain\Cms\Support\Rows;
use App\Domain\Cms\Support\Slugs;
use App\Support\Ids;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PostResource extends ContentResource
{
    protected array $map = [
        'slug' => 'slug', 'title' => 'title', 'excerpt' => 'excerpt', 'bodyMarkdown' => 'body_markdown', 'coverMediaId' => 'cover_media_id', 'authorName' => 'author_name',
        'categoryId' => 'category_id', 'tags' => 'tags', 'featured' => 'is_featured', 'seoTitle' => 'seo_title', 'seoDescription' => 'seo_description', 'seoOgMediaId' => 'seo_og_media_id',
    ];

    protected array $uuids = ['coverMediaId', 'categoryId', 'seoOgMediaId'];

    protected array $bools = ['featured'];

    protected array $jsons = ['tags'];

    public function key(): string
    {
        return 'posts';
    }

    public function table(): string
    {
        return 'cms_post';
    }

    public function entity(): string
    {
        return 'post';
    }

    public function requireIfMatch(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            'slug' => ['string', 'max:120', 'regex:'.Slugs::REGEX], 'title' => ['string', 'max:160'], 'excerpt' => ['string', 'max:400'], 'bodyMarkdown' => ['string', 'max:200000'],
            'coverMediaId' => [new MediaExists], 'authorName' => ['string', 'max:80'],
            'categoryId' => [fn ($a, $v, $fail) => is_string($v) && Ids::isUuid($v) && DB::table('cms_post_category')->where('id', Ids::toBinary($v))->exists() || $fail('Unknown post category.')],
            'tags' => ['array', 'max:12'], 'tags.*' => ['string', 'min:1', 'max:32'], 'featured' => ['boolean'],
            'seoTitle' => ['string', 'max:70'], 'seoDescription' => ['string', 'max:200'], 'seoOgMediaId' => [new MediaExists],
        ];
    }

    public function required(): array
    {
        return ['title', 'bodyMarkdown'];
    }

    public function beforeSave(array &$d, ?object $existing): void
    {
        if (array_key_exists('tags', $d)) {
            $d['tags'] = array_values(array_unique(array_filter(array_map(fn ($t) => mb_strtolower(trim((string) $t)), $d['tags'] ?? []))));
        }
        $body = $d['bodyMarkdown'] ?? $existing?->body_markdown ?? '';
        if ($existing === null ? blank($d['excerpt'] ?? null) : (array_key_exists('excerpt', $d) && blank($d['excerpt']))) {
            $d['excerpt'] = Markdown::plain($body, 200);
        }
    }

    protected function extra(array $d, ?object $existing): array
    {
        $out = [];
        if (array_key_exists('bodyMarkdown', $d)) {
            $out['body_html'] = Markdown::html($d['bodyMarkdown']);
            $out['reading_time_minutes'] = Markdown::readingMinutes($d['bodyMarkdown']);
        }

        return $out;
    }

    public function order(): array
    {
        return ['updated_at', 'desc'];
    }

    public function filter(Builder $q, Request $r): void
    {
        parent::filter($q, $r);
        if (Ids::isUuid($c = (string) $r->query('categoryId'))) {
            $q->where('category_id', Ids::toBinary($c));
        }
        if (($t = trim((string) $r->query('tag'))) !== '') {
            $q->whereJsonContains('tags', mb_strtolower($t));
        }
        if ($r->query('featured') !== null) {
            $q->where('is_featured', filter_var($r->query('featured'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        }
    }

    public function admin(object $r, MediaResolver $media): array
    {
        $cat = $r->category_id === null ? null : DB::table('cms_post_category')->where('id', $r->category_id)->first(['id', 'slug', 'name']);

        return $this->head($r) + [
            'title' => $r->title, 'excerpt' => $r->excerpt, 'bodyMarkdown' => $r->body_markdown, 'bodyHtml' => $r->body_html,
            'coverMediaId' => Rows::id($r->cover_media_id), 'cover' => $media->admin(Rows::id($r->cover_media_id)), 'authorName' => $r->author_name,
            'categoryId' => Rows::id($r->category_id), 'category' => $cat ? ['id' => Rows::id($cat->id), 'slug' => $cat->slug, 'name' => $cat->name] : null,
            'tags' => Rows::json($r->tags), 'readingTimeMinutes' => (int) $r->reading_time_minutes, 'featured' => (bool) $r->is_featured,
            'seoTitle' => $r->seo_title, 'seoDescription' => $r->seo_description, 'seoOgMediaId' => Rows::id($r->seo_og_media_id), 'seoOgImage' => $media->admin(Rows::id($r->seo_og_media_id)),
        ] + $this->tail($r);
    }

    public function mediaIds(object $row): array
    {
        return [Rows::id($row->cover_media_id), Rows::id($row->seo_og_media_id)];
    }
}
