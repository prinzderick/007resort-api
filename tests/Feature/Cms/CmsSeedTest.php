<?php

namespace Tests\Feature\Cms;

use App\Support\Audit\Audit;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class CmsSeedTest extends CmsTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/cms-stock-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** Tiny copies of every real stock file name so the seeder finds each `hero-01`, `pool-01`... prefix. */
    private function fixtureStock(bool $withManifest = true): void
    {
        $real = json_decode((string) file_get_contents(database_path('seeders/stock/manifest.json')), true);
        foreach ($real as $e) {
            file_put_contents($this->dir.'/'.$e['file'], $this->imageBytes('jpeg', 96, 64));
        }
        if ($withManifest) {
            file_put_contents($this->dir.'/manifest.json', json_encode($real));
        }
        config()->set('cms.stock_dir', $this->dir);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'settings' => DB::table('cms_setting')->count(), 'media' => DB::table('cms_media')->count(), 'home' => DB::table('cms_home_section')->count(), 'pages' => DB::table('cms_page')->count(),
            'posts' => DB::table('cms_post')->count(), 'cats' => DB::table('cms_post_category')->count(), 'events' => DB::table('cms_event')->count(), 'albums' => DB::table('cms_gallery_album')->count(),
            'items' => DB::table('cms_gallery_item')->count(),
        ];
    }

    public function test_seed_creates_the_full_demo_content_and_is_idempotent(): void
    {
        $this->fixtureStock();
        $this->assertSame(0, Artisan::call('r007:cms-seed'));
        $c = $this->counts();
        $this->assertSame(8, $c['settings']);
        $this->assertSame(34, $c['media']);
        $this->assertSame(28, $c['home']);
        $this->assertSame(3, $c['pages']);
        $this->assertSame(6, $c['posts']);
        $this->assertSame(3, $c['cats']);
        $this->assertSame(8, $c['events']);
        $this->assertSame(4, $c['albums']);
        $this->assertGreaterThanOrEqual(20, $c['items']);
        $by = DB::table('cms_home_section')->selectRaw('type, COUNT(*) n')->groupBy('type')->pluck('n', 'type')->all();
        $this->assertSame(3, $by['HERO_SLIDE']);
        $this->assertSame(6, $by['HIGHLIGHT']);
        $this->assertSame(4, $by['STAT']);
        $this->assertSame(5, $by['TESTIMONIAL']);
        $this->assertSame(8, $by['FAQ']);
        $this->assertSame(0, DB::table('cms_home_section')->where('is_enabled', 0)->count());

        // the website can consume it
        $home = $this->pub('/home')->assertOk();
        $this->assertCount(3, $home->json('byType.HERO_SLIDE'));
        $this->assertNotNull($home->json('byType.HERO_SLIDE.0.media.url'));
        $this->assertEqualsCanonicalizing(['play', 'splash', 'reset', 'feast'], array_values(array_unique(array_column($home->json('byType.HIGHLIGHT'), 'category'))));
        $this->assertCount(6, $this->pub('/posts')->json('items'));
        $this->assertCount(3, $this->pub('/post-categories')->json('items'));
        $this->assertCount(3, $this->pub('/pages')->json('items'));
        $this->assertCount(4, $this->pub('/gallery/albums')->json('items'));
        $up = $this->pub('/events?upcoming=true&limit=50')->json('items');
        $this->assertNotEmpty($up);
        $this->assertTrue((bool) array_filter($up, fn ($e) => $e['isRecurring']));
        $this->assertNotEmpty($this->pub('/events?upcoming=false')->json('items'), 'past events exist');
        $this->assertTrue($this->pub('/site')->json('announcement.enabled'));
        $this->assertNotNull($this->pub('/site')->json('seo.ogImage.url'));
        $this->assertNotEmpty($this->pub('/pages/about')->json('bodyHtml'));
        $this->assertNotNull($this->pub('/posts/friday-night-live-is-back')->json('cover.url'));
        // every photo went through the media service: credited + variants
        $m = DB::table('cms_media')->first();
        $this->assertNotEmpty(json_decode($m->variants, true));
        $this->assertStringContainsString('Unsplash', (string) $m->credit);

        // second run: nothing duplicated, nothing overwritten
        $before = $c;
        DB::table('cms_page')->where('slug', 'about')->update(['title' => 'Edited by a human']);
        $this->assertSame(0, Artisan::call('r007:cms-seed'));
        $this->assertSame($before, $this->counts());
        $this->assertSame('Edited by a human', DB::table('cms_page')->where('slug', 'about')->value('title'));
        $this->assertTrue(Audit::verifyChain()->valid);
        $this->assertNotEmpty($this->audit('cms.page.create'));
    }

    public function test_seed_falls_back_gracefully_without_a_manifest_or_photos(): void
    {
        // no manifest: any image files in the folder are used
        file_put_contents($this->dir.'/lovely-pool.jpg', $this->imageBytes('jpeg', 96, 64));
        config()->set('cms.stock_dir', $this->dir);
        $this->assertSame(0, Artisan::call('r007:cms-seed'));
        $this->assertSame(1, DB::table('cms_media')->count());
        $this->assertSame('Lovely pool', DB::table('cms_media')->value('alt'));
        $this->assertSame(6, DB::table('cms_post')->count());

        // no photos at all: text content still seeds, hero slides (image required) are skipped rather than crashing
        DB::table('cms_gallery_item')->delete();
        foreach (['cms_gallery_album', 'cms_post', 'cms_page', 'cms_event', 'cms_home_section', 'cms_post_category'] as $t) {
            DB::table($t)->delete();
        }
        DB::table('cms_media')->delete();
        foreach (glob($this->dir.'/*') as $f) {
            unlink($f);
        }
        $this->assertSame(0, Artisan::call('r007:cms-seed'));
        $this->assertSame(0, DB::table('cms_media')->count());
        $this->assertSame(6, DB::table('cms_post')->count());
        $this->assertSame(8, DB::table('cms_event')->count());
        $this->assertSame(0, DB::table('cms_home_section')->where('type', 'HERO_SLIDE')->count());
        $this->assertSame(8, DB::table('cms_home_section')->where('type', 'FAQ')->count());
    }

    public function test_seed_refuses_production_and_demo_seed_hook_is_skipped_under_phpunit(): void
    {
        $this->fixtureStock();
        $this->app['env'] = 'production';
        $this->assertSame(1, Artisan::call('r007:cms-seed'));
        $this->assertSame(0, DB::table('cms_page')->count());
        $this->app['env'] = 'testing';
        // r007:demo-seed (run in setUp) did not import photos or content while testing
        $this->assertSame(0, DB::table('cms_media')->count());
    }

    public function test_bundled_stock_photos_are_credited_small_and_consistent(): void
    {
        $dir = database_path('seeders/stock');
        $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);
        $credits = (string) file_get_contents($dir.'/CREDITS.md');
        $bytes = 0;
        foreach ($manifest as $e) {
            $this->assertFileExists($dir.'/'.$e['file']);
            $this->assertStringContainsString($e['file'], $credits, "{$e['file']} is credited");
            $this->assertNotEmpty($e['alt']);
            $this->assertNotEmpty($e['sourceUrl']);
            $size = getimagesize($dir.'/'.$e['file']);
            $this->assertLessThanOrEqual(1600, $size[0]);
            $this->assertSame('image/jpeg', $size['mime']);
            $bytes += filesize($dir.'/'.$e['file']);
        }
        $this->assertLessThan(12 * 1024 * 1024, $bytes);
        $this->assertGreaterThanOrEqual(30, count($manifest));
        foreach (glob($dir.'/*.jpg') as $f) {
            $this->assertNotEmpty(array_filter($manifest, fn ($e) => $e['file'] === basename($f)), basename($f).' is in the manifest');
        }
    }
}
