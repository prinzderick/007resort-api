<?php

namespace App\Domain\Cms\Services;

use App\Domain\Cms\Support\Rows;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/** Where a media item is referenced (delete guard + `GET /media/{id}/usage`). */
class MediaUsage
{
    /** table => [label column, [[field, column], ...], entity type] */
    private const FK = [
        'cms_page' => ['title', [['heroMediaId', 'hero_media_id'], ['seoOgMediaId', 'seo_og_media_id']], 'page'],
        'cms_post' => ['title', [['coverMediaId', 'cover_media_id'], ['seoOgMediaId', 'seo_og_media_id']], 'post'],
        'cms_event' => ['title', [['coverMediaId', 'cover_media_id'], ['seoOgMediaId', 'seo_og_media_id']], 'event'],
        'cms_gallery_album' => ['title', [['coverMediaId', 'cover_media_id']], 'album'],
    ];

    /** @return list<array{type: string, id: string, label: string, field: string}> */
    public function of(string $mediaId): array
    {
        $bin = Ids::toBinary($mediaId);
        $out = [];
        foreach (self::FK as $table => [$label, $fields, $type]) {
            foreach ($fields as [$field, $col]) {
                foreach (DB::table($table)->where($col, $bin)->get(['id', $label]) as $r) {
                    $out[] = ['type' => $type, 'id' => Rows::id($r->id), 'label' => (string) $r->{$label}, 'field' => $field];
                }
            }
        }
        foreach (DB::table('cms_gallery_item as i')->join('cms_gallery_album as a', 'a.id', '=', 'i.album_id')->where('i.media_id', $bin)->get(['i.id', 'a.title']) as $r) {
            $out[] = ['type' => 'galleryItem', 'id' => Rows::id($r->id), 'label' => (string) $r->title, 'field' => 'mediaId'];
        }
        $like = '%'.$mediaId.'%';
        foreach (DB::table('cms_home_section')->where('payload', 'like', $like)->get(['id', 'type']) as $r) {
            $out[] = ['type' => 'homeSection', 'id' => Rows::id($r->id), 'label' => (string) $r->type, 'field' => 'payload'];
        }
        foreach (DB::table('cms_setting')->where('value', 'like', $like)->get(['id', 'grp']) as $r) {
            $out[] = ['type' => 'setting', 'id' => Rows::id($r->id), 'label' => (string) $r->grp, 'field' => 'value'];
        }

        return $out;
    }
}
