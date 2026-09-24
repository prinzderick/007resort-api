<?php

namespace Tests\Feature\Cms;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MediaAndGalleryTest extends CmsTestCase
{
    public function test_upload_strips_exif_creates_variants_and_returns_absolute_urls(): void
    {
        $bytes = $this->imageBytes('jpeg', 2000, 1000, exif: true);
        $this->assertStringContainsString('SECRETGPS', $bytes);
        $r = $this->upload($this->owner(), $bytes, 'holiday.jpg', ['alt' => 'Pool at dusk', 'credit' => 'Jane / Unsplash', 'sourceUrl' => 'https://unsplash.com/photos/x', 'tags' => ['Pool', 'pool', 'Dusk']])->assertCreated()->assertHeader('ETag')->assertHeader('Location');
        $m = $r->json();
        $this->assertSame('image/jpeg', $m['mimeType']);
        $this->assertSame([2000, 1000], [$m['width'], $m['height']]);
        $this->assertSame('Pool at dusk', $m['alt']);
        $this->assertSame(['pool', 'dusk'], $m['tags']);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $m['dominantColor']);
        $this->assertStringStartsWith('http', $m['url']);
        $this->assertStringContainsString('/cms/', $m['url']);
        $this->assertSame([480, 960, 1600], array_column($m['variants'], 'width'));
        foreach ($m['variants'] as $v) {
            $this->assertSame('webp', $v['format']);
            $this->assertStringStartsWith('http', $v['url']);
        }
        $row = DB::table('cms_media')->first();
        $disk = Storage::disk('public');
        $this->assertTrue($disk->exists($row->path));
        $this->assertStringStartsWith('cms/', $row->path);
        // EXIF/GPS removed from the stored original (and it is still a valid image of the right size)
        $stored = $disk->get($row->path);
        $this->assertStringNotContainsString('SECRETGPS', $stored);
        $this->assertStringNotContainsString('Exif', $stored);
        $this->assertSame([2000, 1000], array_slice(getimagesizefromstring($stored), 0, 2));
        foreach (json_decode($row->variants, true) as $v) {
            $this->assertTrue($disk->exists($v['path']));
            $this->assertSame('image/webp', getimagesizefromstring($disk->get($v['path']))['mime']);
        }
        $this->assertSame(480, getimagesizefromstring($disk->get(json_decode($row->variants, true)[0]['path']))[0]);
        $this->assertNotEmpty($this->audit('cms.media.upload', $m['id']));

        // small image: only variants below its width + one at its own width; png/webp/avif accepted
        $small = $this->upload($this->owner(), $this->imageBytes('png', 700, 400), 'a.png')->assertCreated()->json();
        $this->assertSame([480, 700], array_column($small['variants'], 'width'));
        $this->assertSame('image/png', $small['mimeType']);
        $this->upload($this->owner(), $this->imageBytes('webp', 500, 500), 'b.webp')->assertCreated()->assertJsonPath('mimeType', 'image/webp');
        if (function_exists('imageavif')) {
            $this->upload($this->owner(), $this->imageBytes('avif', 500, 500), 'c.avif')->assertCreated()->assertJsonPath('mimeType', 'image/avif');
        }
        // the public shape has no admin internals; a list finds it
        $this->assertArrayNotHasKey('usageCount', $this->pubMediaShape($m['id']));
        $this->owner()->get('/admin/cms/media?q=Pool')->assertJsonCount(1, 'items');
        $this->owner()->get('/admin/cms/media?tag=dusk')->assertJsonCount(1, 'items');
        $this->owner()->get('/admin/cms/media/'.$m['id'])->assertOk()->assertJsonPath('usageCount', 1);
    }

    /** @return array<string, mixed> */
    private function pubMediaShape(string $id): array
    {
        $g = $this->owner()->post('/admin/cms/gallery/albums', ['title' => 'A'])->json();
        $this->owner()->post("/admin/cms/gallery/albums/{$g['id']}/items", ['mediaId' => $id])->assertCreated();
        $this->owner()->post("/admin/cms/gallery/albums/{$g['id']}/publish")->assertOk();

        return $this->pub('/gallery/albums/a')->assertOk()->json('items.0.media');
    }

    public function test_upload_rejects_fake_mime_svg_oversize_and_junk(): void
    {
        $o = $this->owner();
        // a text/HTML/PHP file dressed up as a jpeg
        $this->upload($o, '<?php echo 1;', 'shell.jpg')->assertStatus(415)->assertJsonPath('code', 'media_type_unsupported');
        $this->upload($o, '<html><script>alert(1)</script></html>', 'x.png')->assertStatus(415);
        // SVG in every disguise
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script></svg>';
        $this->upload($o, $svg, 'logo.svg')->assertStatus(415);
        $this->upload($o, $svg, 'logo.png')->assertStatus(415);
        $this->upload($o, $svg, 'logo.jpg')->assertStatus(415);
        // a GIF is a real image but not on the list
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        $this->upload($o, $gif, 'a.gif')->assertStatus(415);
        // real image with the wrong extension is fine (type is detected by content)
        $this->upload($o, $this->imageBytes('png', 300, 300), 'really-a-png.jpg')->assertCreated()->assertJsonPath('mimeType', 'image/png');
        // polyglot: valid JPEG followed by PHP; appended payload must not survive re-encoding
        $poly = $this->imageBytes('jpeg', 300, 300).'<?php system($_GET[0]); ?>';
        $r = $this->upload($o, $poly, 'poly.jpg')->assertCreated()->json();
        $this->assertStringNotContainsString('system(', Storage::disk('public')->get(DB::table('cms_media')->where('id', hex2bin(str_replace('-', '', $r['id'])))->value('path')));
        // oversize (8 MB + 1) and empty
        $big = str_repeat('a', 8 * 1024 * 1024 + 1);
        $this->upload($o, $big, 'big.jpg')->assertStatus(413)->assertJsonPath('code', 'media_too_large');
        $this->upload($o, '', 'empty.jpg')->assertStatus(422);
        // no file / not a file
        $this->withHeaders($o->headers(['Accept' => 'application/json', 'Idempotency-Key' => 'k1']))->post('/api/v1/admin/cms/media', ['alt' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['file']);
        // absurd dimensions are refused before decoding
        $this->upload($o, $this->imageBytes('png', 8100, 10), 'wide.png')->assertStatus(422);
        // nothing leaked to disk or DB for refused files
        $this->assertSame(2, DB::table('cms_media')->count());
        // permission: media upload needs cms.media.manage
        $this->upload($this->roleApi('CASHIER'), $this->imageBytes(), 'p.jpg')->assertStatus(403);
    }

    public function test_media_alt_edit_usage_and_delete_guard(): void
    {
        $o = $this->owner();
        $m = $this->newMedia('First alt');
        $p = $o->patch("/admin/cms/media/{$m['id']}", ['alt' => 'Better alt', 'credit' => 'Someone', 'tags' => ['A', 'b']])->assertOk();
        $this->assertSame('Better alt', $p->json('alt'));
        $this->assertSame(['a', 'b'], $p->json('tags'));
        $this->assertGreaterThan($m['rowVersion'], $p->json('rowVersion'));
        $this->assertSame('First alt', $this->audit('cms.media.update', $m['id'])[0]->old['alt']);
        $o->patch('/admin/cms/media/0192a1b2-0000-7000-8000-000000000000', ['alt' => 'x'])->assertStatus(404);

        $page = $o->post('/admin/cms/pages', ['title' => 'Uses media', 'bodyMarkdown' => 'x', 'heroMediaId' => $m['id']])->assertCreated()->json();
        $ev = $o->post('/admin/cms/events', ['title' => 'E', 'category' => 'FOOD', 'startsAt' => '2026-12-01T18:00:00Z', 'endsAt' => '2026-12-01T19:00:00Z', 'coverMediaId' => $m['id']])->assertCreated()->json();
        $u = $o->get("/admin/cms/media/{$m['id']}/usage")->assertOk();
        $this->assertSame(2, $u->json('usageCount'));
        $this->assertEqualsCanonicalizing(['page', 'event'], array_column($u->json('usage'), 'type'));
        $this->assertSame('Uses media', collect($u->json('usage'))->firstWhere('type', 'page')['label']);
        $del = $o->delete("/admin/cms/media/{$m['id']}")->assertStatus(409);
        $this->assertSame('media_in_use', $del->json('code'));
        $this->assertSame(2, $del->json('usageCount'));
        $this->assertCount(2, $del->json('usage'));
        $paths = collect(DB::table('cms_media')->get())->flatMap(fn ($r) => [$r->path, ...array_column(json_decode($r->variants, true), 'path')]);
        foreach ($paths as $path) {
            $this->assertTrue(Storage::disk('public')->exists($path), 'files survive a refused delete');
        }

        $o->delete("/admin/cms/pages/{$page['id']}")->assertNoContent();
        $o->delete("/admin/cms/events/{$ev['id']}")->assertNoContent();
        $o->delete("/admin/cms/media/{$m['id']}")->assertNoContent();
        foreach ($paths as $path) {
            $this->assertFalse(Storage::disk('public')->exists($path), 'files are removed with the row');
        }
        $this->assertNotEmpty($this->audit('cms.media.delete', $m['id']));
        $o->get("/admin/cms/media/{$m['id']}")->assertStatus(404);
    }

    public function test_media_url_can_be_overridden_for_a_cloud_node_or_cdn(): void
    {
        config()->set('cms.media.url', 'https://cdn.example.com/site');
        $m = $this->newMedia('CDN');
        $this->assertStringStartsWith('https://cdn.example.com/site/cms/', $m['url']);
        $this->assertStringStartsWith('https://cdn.example.com/site/cms/', $m['variants'][0]['url']);
    }

    public function test_gallery_albums_items_and_public_view(): void
    {
        $o = $this->owner();
        [$a, $b, $c] = [$this->newMedia('Pool'), $this->newMedia('Court'), $this->newMedia('Bar')];
        $album = $o->post('/admin/cms/gallery/albums', ['title' => 'Summer', 'description' => 'Sun', 'coverMediaId' => $a['id'], 'sortOrder' => 20])->assertCreated()->json();
        $this->assertSame('summer', $album['slug']);
        $items = $o->post("/admin/cms/gallery/albums/{$album['id']}/items", ['items' => [
            ['mediaId' => $a['id'], 'caption' => 'Lounging', 'featured' => true, 'category' => 'pool', 'tags' => ['Sun']],
            ['mediaId' => $b['id'], 'altText' => 'A tennis court'],
        ]])->assertCreated()->json('items');
        $this->assertCount(2, $items);
        $this->assertSame(10, $items[0]['sortOrder']);
        $this->assertSame(['sun'], $items[0]['tags']);
        $single = $o->post("/admin/cms/gallery/albums/{$album['id']}/items", ['mediaId' => $c['id']])->assertCreated()->json();
        $this->assertSame(30, $single['sortOrder']);
        $o->post("/admin/cms/gallery/albums/{$album['id']}/items", ['mediaId' => $c['id']])->assertStatus(409)->assertJsonPath('code', 'duplicate_item');
        $o->post("/admin/cms/gallery/albums/{$album['id']}/items", ['items' => [['mediaId' => $a['id']], ['mediaId' => $a['id']]]])->assertStatus(409);
        $o->post("/admin/cms/gallery/albums/{$album['id']}/items", ['mediaId' => '0192a1b2-0000-7000-8000-000000000000'])->assertStatus(422);

        // drafts stay private
        $this->pub('/gallery/albums')->assertOk()->assertJsonCount(0, 'items');
        $this->pub('/gallery/albums/summer')->assertStatus(404);
        $o->post("/admin/cms/gallery/albums/{$album['id']}/publish")->assertOk();
        $list = $this->pub('/gallery/albums')->assertOk();
        $this->assertSame(3, $list->json('items.0.itemCount'));
        $this->assertSame($a['id'], $list->json('items.0.cover.id'));
        $det = $this->pub('/gallery/albums/summer')->assertOk();
        $this->assertSame([$a['id'], $b['id'], $c['id']], array_column(array_column($det->json('items'), 'media'), 'id'));
        $this->assertSame('Pool', $det->json('items.0.alt'), 'item alt falls back to the media alt');
        $this->assertSame('A tennis court', $det->json('items.1.alt'));
        $this->assertTrue($det->json('items.0.featured'));

        // reorder + edit + delete
        $o->post("/admin/cms/gallery/albums/{$album['id']}/items/reorder", ['items' => [['id' => $single['id'], 'sortOrder' => 1]]])->assertOk();
        $this->assertSame($c['id'], $this->pub('/gallery/albums/summer')->json('items.0.media.id'));
        $o->patch("/admin/cms/gallery/items/{$single['id']}", ['caption' => 'Cocktails', 'featured' => true])->assertOk()->assertJsonPath('caption', 'Cocktails');
        $o->delete("/admin/cms/gallery/items/{$single['id']}")->assertNoContent();
        $this->assertCount(2, $o->get("/admin/cms/gallery/albums/{$album['id']}/items")->json('items'));
        // media in an album is protected until the item is removed
        $o->delete("/admin/cms/media/{$a['id']}")->assertStatus(409);
        $o->delete("/admin/cms/media/{$c['id']}")->assertNoContent();
        // album ordering
        $second = $this->published('gallery/albums', ['title' => 'Winter', 'sortOrder' => 5]);
        $this->assertSame(['winter', 'summer'], array_column($this->pub('/gallery/albums')->json('items'), 'slug'));
        $o->post('/admin/cms/gallery/albums/reorder', ['items' => [['id' => $album['id'], 'sortOrder' => 1]]])->assertOk();
        $this->assertSame(['summer', 'winter'], array_column($this->pub('/gallery/albums')->json('items'), 'slug'));
        // deleting a published album needs archive first, and cascades its items
        $o->delete("/admin/cms/gallery/albums/{$album['id']}")->assertStatus(409)->assertJsonPath('code', 'must_archive_first');
        $o->post("/admin/cms/gallery/albums/{$album['id']}/archive")->assertOk();
        $o->delete("/admin/cms/gallery/albums/{$album['id']}")->assertNoContent();
        $this->assertSame(0, DB::table('cms_gallery_item')->count());
        $o->get("/admin/cms/gallery/albums/{$second['id']}")->assertOk();
    }
}
