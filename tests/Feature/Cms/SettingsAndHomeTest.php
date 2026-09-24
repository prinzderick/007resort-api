<?php

namespace Tests\Feature\Cms;

use App\Support\Audit\Audit;

class SettingsAndHomeTest extends CmsTestCase
{
    public function test_settings_defaults_read_update_and_concurrency(): void
    {
        $o = $this->owner();
        $all = $o->get('/admin/cms/settings')->assertOk();
        $this->assertSame(['announcement', 'booking', 'brand', 'contact', 'footer', 'hours', 'seo', 'social'], collect($all->json('groups'))->keys()->sort()->values()->all());
        $this->assertCount(7, $all->json('groups.hours.value.weekly'));

        $g = $o->get('/admin/cms/settings/brand')->assertOk()->assertHeader('ETag');
        $v = $g->json('rowVersion');
        $o->put('/admin/cms/settings/brand', ['value' => ['name' => 'X']])->assertStatus(428);
        $u = $o->put('/admin/cms/settings/brand', ['value' => ['name' => '007 Resort', 'tagline' => 'New tagline', 'ignored' => 'dropped']], $this->etag($g))->assertOk();
        $this->assertSame($v + 1, $u->json('rowVersion'));
        $this->assertSame('New tagline', $u->json('value.tagline'));
        $this->assertArrayNotHasKey('ignored', $u->json('value'));
        $o->put('/admin/cms/settings/brand', ['value' => ['name' => 'stale']], $this->etag($g))->assertStatus(412)->assertJsonPath('code', 'concurrency_conflict');
        // no-op keeps the version and writes no audit row
        $same = $o->put('/admin/cms/settings/brand', ['value' => ['name' => '007 Resort', 'tagline' => 'New tagline']], ['If-Match' => '"'.($v + 1).'"'])->assertOk();
        $this->assertSame($v + 1, $same->json('rowVersion'));
        $this->assertCount(1, $this->audit('cms.setting.brand.update'));
        $a = $this->audit('cms.setting.brand.update')[0];
        $this->assertSame('New tagline', $a->new['value']['tagline']);
        $o->get('/admin/cms/settings/nope')->assertStatus(404);
    }

    public function test_settings_validation(): void
    {
        $o = $this->owner();
        $v = fn (string $g) => ['If-Match' => '"'.$o->get("/admin/cms/settings/{$g}")->json('rowVersion').'"'];
        $o->put('/admin/cms/settings/brand', ['value' => ['tagline' => 'no name']], $v('brand'))->assertStatus(422)->assertJsonValidationErrors(['name']);
        $o->put('/admin/cms/settings/contact', ['value' => ['email' => 'nope']], $v('contact'))->assertStatus(422)->assertJsonValidationErrors(['email']);
        $o->put('/admin/cms/settings/social', ['value' => ['instagram' => 'javascript:alert(1)']], $v('social'))->assertStatus(422)->assertJsonValidationErrors(['instagram']);
        $o->put('/admin/cms/settings/announcement', ['value' => ['enabled' => true]], $v('announcement'))->assertStatus(422)->assertJsonValidationErrors(['text']);
        $o->put('/admin/cms/settings/announcement', ['value' => ['enabled' => true, 'text' => 'Hi', 'link' => 'javascript:x']], $v('announcement'))->assertStatus(422)->assertJsonValidationErrors(['link']);
        $o->put('/admin/cms/settings/hours', ['value' => ['weekly' => [['day' => 'MON']]]], $v('hours'))->assertStatus(422);
        $o->put('/admin/cms/settings/brand', ['value' => ['name' => 'X', 'logoMediaId' => '0192a1b2-0000-7000-8000-000000000000']], $v('brand'))->assertStatus(422)->assertJsonValidationErrors(['logoMediaId']);
        $o->put('/admin/cms/settings/brand', ['nothing' => 1], $v('brand'))->assertStatus(422);
        // hours: every weekday exactly once, closed days lose their times, holidays sorted
        $week = array_map(fn ($d) => ['day' => $d, 'open' => '09:00', 'close' => '21:00'], ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN']);
        $week[6] = ['day' => 'SUN', 'closed' => true, 'open' => '10:00', 'close' => '12:00'];
        $ok = $o->put('/admin/cms/settings/hours', ['value' => ['weekly' => array_reverse($week), 'holidays' => [['date' => '2026-12-26', 'label' => 'Boxing'], ['date' => '2026-12-25', 'label' => 'Xmas', 'closed' => true]]]], $v('hours'))->assertOk();
        $this->assertSame('MON', $ok->json('value.weekly.0.day'));
        $this->assertNull($ok->json('value.weekly.6.open'));
        $this->assertTrue($ok->json('value.weekly.6.closed'));
        $this->assertSame('2026-12-25', $ok->json('value.holidays.0.date'));
    }

    public function test_public_site_expands_media_and_is_cacheable(): void
    {
        $m = $this->newMedia('Logo');
        $o = $this->owner();
        $g = $o->get('/admin/cms/settings/brand');
        $o->put('/admin/cms/settings/brand', ['value' => ['name' => 'Resort', 'logoMediaId' => $m['id']]], $this->etag($g))->assertOk();
        $r = $this->pub('/site')->assertOk()->assertHeader('ETag')->assertHeader('Cache-Control', 'max-age=60, public, stale-while-revalidate=300');
        $this->assertSame($m['id'], $r->json('brand.logo.id'));
        $this->assertArrayNotHasKey('logoMediaId', $r->json('brand'));
        $this->assertStringStartsWith('http', $r->json('brand.logo.url'));
        $this->assertCount(7, $r->json('hours.weekly'));
        foreach (['brand', 'contact', 'hours', 'social', 'seo', 'announcement', 'booking', 'footer', 'updatedAt'] as $k) {
            $this->assertArrayHasKey($k, $r->json());
        }
        $this->pub('/site', ['If-None-Match' => $r->headers->get('ETag')])->assertStatus(304);
        // media referenced by settings cannot be deleted
        $o->delete("/admin/cms/media/{$m['id']}")->assertStatus(409)->assertJsonPath('code', 'media_in_use')->assertJsonPath('usage.0.type', 'setting');
    }

    public function test_home_sections_typed_validation_enable_order_and_public_grouping(): void
    {
        $o = $this->owner();
        $m = $this->newMedia('Hero');
        // validation per type
        $o->post('/admin/cms/home-sections', ['type' => 'HERO_SLIDE', 'payload' => ['headline' => 'No image']])->assertStatus(422);
        $o->post('/admin/cms/home-sections', ['type' => 'HIGHLIGHT', 'payload' => ['title' => 'T', 'blurb' => 'B', 'category' => 'swim']])->assertStatus(422);
        $o->post('/admin/cms/home-sections', ['type' => 'TESTIMONIAL', 'payload' => ['name' => 'N', 'quote' => 'Q', 'rating' => 9]])->assertStatus(422);
        $o->post('/admin/cms/home-sections', ['type' => 'CTA_BAND', 'payload' => ['title' => 'T', 'ctaLabel' => 'Go', 'ctaLink' => 'javascript:alert(1)']])->assertStatus(422);
        $o->post('/admin/cms/home-sections', ['type' => 'NOPE', 'payload' => []])->assertStatus(422);

        $hero = $o->post('/admin/cms/home-sections', ['type' => 'HERO_SLIDE', 'payload' => ['headline' => 'Play', 'mediaId' => $m['id'], 'ctaLabel' => 'Book', 'ctaLink' => '/book', 'junk' => 1]])->assertCreated()->json();
        $this->assertFalse($hero['enabled'], 'new sections start disabled');
        $this->assertSame('LEFT', $hero['payload']['alignment']);
        $this->assertArrayNotHasKey('junk', $hero['payload']);
        $faq = $o->post('/admin/cms/home-sections', ['type' => 'FAQ', 'payload' => ['question' => 'Open?', 'answer' => '**Yes** <script>alert(1)</script>']])->assertCreated()->json();
        $stat = $o->post('/admin/cms/home-sections', ['type' => 'STAT', 'payload' => ['label' => 'Courts', 'value' => '9']])->assertCreated()->json();

        $this->assertSame([], $this->pub('/home')->json('sections'), 'disabled sections are invisible');
        $o->post("/admin/cms/home-sections/{$hero['id']}/enable")->assertOk()->assertJsonPath('enabled', true);
        $o->post("/admin/cms/home-sections/{$faq['id']}/enable")->assertOk();
        $o->post("/admin/cms/home-sections/{$stat['id']}/enable")->assertOk();
        $o->post("/admin/cms/home-sections/{$stat['id']}/disable")->assertOk()->assertJsonPath('enabled', false);

        $home = $this->pub('/home')->assertOk();
        $this->assertSame(['HERO_SLIDE', 'FAQ'], array_column($home->json('sections'), 'type'));
        $this->assertSame($m['id'], $home->json('byType.HERO_SLIDE.0.media.id'));
        $this->assertArrayNotHasKey('mediaId', $home->json('byType.HERO_SLIDE.0'));
        $this->assertSame('Play', $home->json('byType.HERO_SLIDE.0.headline'));
        $this->assertStringContainsString('<strong>Yes</strong>', $home->json('byType.FAQ.0.answerHtml'));
        $this->assertStringNotContainsString('<script', $home->json('byType.FAQ.0.answerHtml'));
        foreach (['HERO_SLIDE', 'HIGHLIGHT', 'STAT', 'TESTIMONIAL', 'FAQ', 'PARTNER', 'CTA_BAND'] as $t) {
            $this->assertArrayHasKey($t, $home->json('byType'));
        }

        // reorder (bulk)
        $o->post('/admin/cms/home-sections/reorder', ['items' => [['id' => $faq['id'], 'sortOrder' => 1], ['id' => $hero['id'], 'sortOrder' => 2]]])->assertOk()->assertJsonPath('updated', 2);
        $this->assertSame(['FAQ', 'HERO_SLIDE'], array_column($this->pub('/home')->json('sections'), 'type'));
        $o->post('/admin/cms/home-sections/reorder', ['items' => [['id' => '0192a1b2-0000-7000-8000-000000000000', 'sortOrder' => 1]]])->assertStatus(422);
        $this->assertNotEmpty($this->audit('cms.home_section.reorder'));

        // patch + admin list with media map
        $p = $o->patch("/admin/cms/home-sections/{$hero['id']}", ['payload' => ['headline' => 'Play more', 'mediaId' => $m['id']]])->assertOk();
        $this->assertSame('Play more', $p->json('payload.headline'));
        $list = $o->get('/admin/cms/home-sections?type=HERO_SLIDE')->assertOk();
        $this->assertCount(1, $list->json('items'));
        $this->assertArrayHasKey($m['id'], (array) $list->json('media'));
        $o->get('/admin/cms/home-sections?enabled=false')->assertJsonCount(1, 'items');
        $o->patch("/admin/cms/home-sections/{$hero['id']}", ['type' => 'STAT'])->assertOk()->assertJsonPath('type', 'HERO_SLIDE');

        // media used by a section is protected; deleting the section frees it
        $o->delete("/admin/cms/media/{$m['id']}")->assertStatus(409)->assertJsonPath('usage.0.type', 'homeSection');
        $o->delete("/admin/cms/home-sections/{$hero['id']}")->assertNoContent();
        $o->delete("/admin/cms/media/{$m['id']}")->assertNoContent();
        $this->assertNotEmpty($this->audit('cms.home_section.create'));
        $this->assertNotEmpty($this->audit('cms.home_section.enable'));
        $this->assertNotEmpty($this->audit('cms.home_section.delete'));
        $this->assertTrue(Audit::verifyChain()->valid);
    }

    public function test_meta_and_summary_and_markdown_preview(): void
    {
        $o = $this->owner();
        $meta = $o->get('/admin/cms/meta')->assertOk();
        $this->assertSame(['SPORT', 'MUSIC', 'PARTY', 'WELLNESS', 'FOOD', 'OTHER'], $meta->json('eventCategories'));
        $this->assertSame('HERO_SLIDE', array_key_first($meta->json('homeSectionTypes')));
        $this->assertSame(8388608, $meta->json('media.maxBytes'));
        $o->get('/admin/cms/summary')->assertOk()->assertJsonStructure(['pages' => ['draft', 'published'], 'subscribers' => ['pending'], 'messages' => ['new']]);
        $html = $o->post('/admin/cms/render-preview', ['markdown' => "# Hi\n<script>alert(1)</script>\n[x](javascript:alert(1))\n![i](data:text/html;base64,AAAA)"])->assertOk()->json('html');
        $this->assertStringContainsString('<h1>Hi</h1>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('data:text', $html);
    }
}
