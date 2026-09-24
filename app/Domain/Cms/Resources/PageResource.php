<?php

namespace App\Domain\Cms\Resources;

use App\Domain\Cms\Support\Markdown;
use App\Domain\Cms\Support\MediaExists;
use App\Domain\Cms\Support\MediaResolver;
use App\Domain\Cms\Support\Rows;
use App\Domain\Cms\Support\Slugs;

class PageResource extends ContentResource
{
    protected array $map = [
        'slug' => 'slug', 'title' => 'title', 'subtitle' => 'subtitle', 'heroMediaId' => 'hero_media_id', 'bodyMarkdown' => 'body_markdown',
        'seoTitle' => 'seo_title', 'seoDescription' => 'seo_description', 'seoOgMediaId' => 'seo_og_media_id', 'showInFooter' => 'show_in_footer', 'sortOrder' => 'sort_order',
    ];

    protected array $uuids = ['heroMediaId', 'seoOgMediaId'];

    protected array $bools = ['showInFooter'];

    public function key(): string
    {
        return 'pages';
    }

    public function table(): string
    {
        return 'cms_page';
    }

    public function entity(): string
    {
        return 'page';
    }

    public function requireIfMatch(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            'slug' => ['string', 'max:120', 'regex:'.Slugs::REGEX], 'title' => ['string', 'max:160'], 'subtitle' => ['string', 'max:200'],
            'heroMediaId' => [new MediaExists], 'bodyMarkdown' => ['string', 'max:200000'], 'seoTitle' => ['string', 'max:70'], 'seoDescription' => ['string', 'max:200'],
            'seoOgMediaId' => [new MediaExists], 'showInFooter' => ['boolean'], 'sortOrder' => ['integer', 'between:-100000,100000'],
        ];
    }

    public function required(): array
    {
        return ['title', 'bodyMarkdown'];
    }

    protected function extra(array $d, ?object $existing): array
    {
        return array_key_exists('bodyMarkdown', $d) ? ['body_html' => Markdown::html($d['bodyMarkdown'])] : [];
    }

    public function admin(object $r, MediaResolver $media): array
    {
        return $this->head($r) + [
            'title' => $r->title, 'subtitle' => $r->subtitle, 'heroMediaId' => Rows::id($r->hero_media_id), 'hero' => $media->admin(Rows::id($r->hero_media_id)),
            'bodyMarkdown' => $r->body_markdown, 'bodyHtml' => $r->body_html, 'seoTitle' => $r->seo_title, 'seoDescription' => $r->seo_description,
            'seoOgMediaId' => Rows::id($r->seo_og_media_id), 'seoOgImage' => $media->admin(Rows::id($r->seo_og_media_id)),
            'showInFooter' => (bool) $r->show_in_footer, 'sortOrder' => (int) $r->sort_order,
        ] + $this->tail($r);
    }

    public function mediaIds(object $row): array
    {
        return [Rows::id($row->hero_media_id), Rows::id($row->seo_og_media_id)];
    }
}
