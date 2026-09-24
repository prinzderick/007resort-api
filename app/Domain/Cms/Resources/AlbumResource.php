<?php

namespace App\Domain\Cms\Resources;

use App\Domain\Cms\Support\MediaExists;
use App\Domain\Cms\Support\MediaResolver;
use App\Domain\Cms\Support\Rows;
use App\Domain\Cms\Support\Slugs;
use Illuminate\Support\Facades\DB;

class AlbumResource extends ContentResource
{
    protected array $map = ['slug' => 'slug', 'title' => 'title', 'description' => 'description', 'coverMediaId' => 'cover_media_id', 'sortOrder' => 'sort_order'];

    protected array $uuids = ['coverMediaId'];

    public function key(): string
    {
        return 'gallery/albums';
    }

    public function table(): string
    {
        return 'cms_gallery_album';
    }

    public function entity(): string
    {
        return 'gallery_album';
    }

    public function order(): array
    {
        return ['sort_order', 'asc'];
    }

    public function fields(): array
    {
        return [
            'slug' => ['string', 'max:120', 'regex:'.Slugs::REGEX], 'title' => ['string', 'max:120'], 'description' => ['string', 'max:1000'],
            'coverMediaId' => [new MediaExists], 'sortOrder' => ['integer', 'between:-100000,100000'],
        ];
    }

    public function required(): array
    {
        return ['title'];
    }

    public function admin(object $r, MediaResolver $media): array
    {
        return $this->head($r) + [
            'title' => $r->title, 'description' => $r->description, 'coverMediaId' => Rows::id($r->cover_media_id), 'cover' => $media->admin(Rows::id($r->cover_media_id)),
            'sortOrder' => (int) $r->sort_order, 'itemCount' => DB::table('cms_gallery_item')->where('album_id', $r->id)->count(),
        ] + $this->tail($r);
    }

    public function mediaIds(object $row): array
    {
        return [Rows::id($row->cover_media_id)];
    }
}
