<?php

namespace App\Domain\Cms\Services;

use App\Domain\Cms\Support\CmsAudit;
use App\Domain\Cms\Support\MediaExists;
use App\Domain\Cms\Support\MediaResolver;
use App\Domain\Cms\Support\Rows;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class GalleryService
{
    public function __construct(private readonly MediaResolver $media) {}

    /** @return array<string, mixed> */
    public function present(object $r): array
    {
        $mid = Rows::id($r->media_id);

        return [
            'id' => Rows::id($r->id), 'albumId' => Rows::id($r->album_id), 'mediaId' => $mid, 'media' => $this->media->admin($mid), 'caption' => $r->caption, 'altText' => $r->alt_text,
            'category' => $r->category, 'tags' => Rows::json($r->tags), 'featured' => (bool) $r->is_featured, 'sortOrder' => (int) $r->sort_order, 'rowVersion' => (int) $r->row_version,
            'createdAt' => Rows::iso($r->created_at), 'updatedAt' => Rows::iso($r->updated_at),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function items(string $albumId): array
    {
        $rows = DB::table('cms_gallery_item')->where('album_id', $this->album($albumId)->id)->orderBy('sort_order')->orderBy('id')->get();
        $this->media->load($rows->map(fn ($r) => Rows::id($r->media_id)));

        return $rows->map(fn ($r) => $this->present($r))->all();
    }

    public function album(string $id): object
    {
        return (Ids::isUuid($id) ? DB::table('cms_gallery_album')->where('id', Ids::toBinary($id))->first() : null) ?? throw ApiProblem::notFound('not_found', 'Album not found.');
    }

    /** @return array<string, list<mixed>> */
    private function rules(bool $create, string $p = ''): array
    {
        $req = $create ? ['required'] : ['sometimes'];

        return [
            $p.'mediaId' => [...$req, new MediaExists], $p.'caption' => ['sometimes', 'nullable', 'string', 'max:300'], $p.'altText' => ['sometimes', 'nullable', 'string', 'max:200'],
            $p.'category' => ['sometimes', 'nullable', 'string', 'max:60'], $p.'tags' => ['sometimes', 'nullable', 'array', 'max:12'], $p.'tags.*' => ['string', 'max:32'],
            $p.'featured' => ['sometimes', 'boolean'], $p.'sortOrder' => ['sometimes', 'integer', 'between:-1000000,1000000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input  one item or {items: [...]}
     * @return list<array<string, mixed>>
     */
    public function add(string $albumId, array $input): array
    {
        $album = $this->album($albumId);
        $many = isset($input['items']);
        $rules = $many ? ['items' => ['required', 'array', 'min:1', 'max:50'], ...$this->rules(true, 'items.*.')] : $this->rules(true);
        $data = Validator::make($input, $rules)->validate();
        $list = $many ? $data['items'] : [$data];
        $mediaIds = array_map(fn ($i) => $i['mediaId'], $list);
        if (count(array_unique($mediaIds)) !== count($mediaIds)) {
            throw ApiProblem::conflict('duplicate_item', 'The same media appears twice in the request.');
        }

        return DB::transaction(function () use ($album, $list, $albumId) {
            DB::table('cms_gallery_album')->where('id', $album->id)->lockForUpdate()->first();
            $max = (int) DB::table('cms_gallery_item')->where('album_id', $album->id)->max('sort_order');
            $out = [];
            foreach ($list as $i) {
                $mb = Ids::toBinary($i['mediaId']);
                if (DB::table('cms_gallery_item')->where('album_id', $album->id)->where('media_id', $mb)->exists()) {
                    throw ApiProblem::conflict('duplicate_item', 'This media is already in the album.');
                }
                $id = Ids::uuid7();
                $max += 10;
                DB::table('cms_gallery_item')->insert([
                    'id' => Ids::toBinary($id), 'album_id' => $album->id, 'media_id' => $mb, 'caption' => $i['caption'] ?? null, 'alt_text' => $i['altText'] ?? null, 'category' => $i['category'] ?? null,
                    'tags' => isset($i['tags']) ? Rows::enc(array_values(array_unique(array_map('mb_strtolower', $i['tags'])))) : null, 'is_featured' => (int) ($i['featured'] ?? false),
                    'sort_order' => $i['sortOrder'] ?? $max, 'row_version' => 1, 'created_at' => Rows::now(), 'updated_at' => Rows::now(),
                ]);
                $row = DB::table('cms_gallery_item')->where('id', Ids::toBinary($id))->first();
                CmsAudit::record('cms.gallery_item.create', 'CmsGalleryItem', $id, null, ['albumId' => $albumId, 'mediaId' => $i['mediaId'], 'caption' => $row->caption]);
                $out[] = $row;
            }
            DB::table('cms_gallery_album')->where('id', $album->id)->update(['row_version' => $album->row_version + 1, 'updated_at' => Rows::now()]);
            $this->media->load(array_map(fn ($r) => Rows::id($r->media_id), $out));

            return array_map(fn ($r) => $this->present($r), $out);
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function update(string $id, array $input): array
    {
        $d = Validator::make($input, array_diff_key($this->rules(false), ['mediaId' => 1]))->validate();

        return DB::transaction(function () use ($id, $d) {
            $row = (Ids::isUuid($id) ? DB::table('cms_gallery_item')->where('id', Ids::toBinary($id))->lockForUpdate()->first() : null) ?? throw ApiProblem::notFound('not_found', 'Item not found.');
            $set = [];
            foreach (['caption' => 'caption', 'altText' => 'alt_text', 'category' => 'category', 'sortOrder' => 'sort_order'] as $in => $col) {
                if (array_key_exists($in, $d)) {
                    $set[$col] = $d[$in] === '' ? null : $d[$in];
                }
            }
            if (array_key_exists('tags', $d)) {
                $set['tags'] = $d['tags'] === null ? null : Rows::enc(array_values(array_unique(array_map('mb_strtolower', $d['tags']))));
            }
            if (array_key_exists('featured', $d)) {
                $set['is_featured'] = (int) $d['featured'];
            }
            if ($set !== []) {
                DB::table('cms_gallery_item')->where('id', $row->id)->update($set + ['row_version' => $row->row_version + 1, 'updated_at' => Rows::now()]);
                CmsAudit::record('cms.gallery_item.update', 'CmsGalleryItem', $id, ['caption' => $row->caption, 'altText' => $row->alt_text, 'category' => $row->category, 'sortOrder' => (int) $row->sort_order], $set);
            }

            return $this->present(DB::table('cms_gallery_item')->where('id', $row->id)->first());
        });
    }

    public function delete(string $id): void
    {
        DB::transaction(function () use ($id) {
            $row = (Ids::isUuid($id) ? DB::table('cms_gallery_item')->where('id', Ids::toBinary($id))->lockForUpdate()->first() : null) ?? throw ApiProblem::notFound('not_found', 'Item not found.');
            DB::table('cms_gallery_item')->where('id', $row->id)->delete();
            CmsAudit::record('cms.gallery_item.delete', 'CmsGalleryItem', $id, ['albumId' => Rows::id($row->album_id), 'mediaId' => Rows::id($row->media_id)], null);
        });
    }
}
