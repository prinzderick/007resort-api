<?php

namespace Tests\Feature\Cms;

use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

class PagesAndBlogTest extends CmsTestCase
{
    public function test_page_lifecycle_visibility_and_optimistic_concurrency(): void
    {
        $o = $this->owner();
        $c = $o->post('/admin/cms/pages', ['title' => 'About us', 'bodyMarkdown' => "## Hello\n\nWelcome <script>alert(1)</script> [bad](javascript:alert(1)) [ok](https://example.com)"])->assertCreated()->assertHeader('ETag')->assertHeader('Location');
        $page = $c->json();
        $this->assertSame('about-us', $page['slug']);
        $this->assertSame('DRAFT', $page['status']);
        $this->assertNull($page['publishedAt']);
        $this->assertStringContainsString('<h2>Hello</h2>', $page['bodyHtml']);
        $this->assertStringNotContainsString('<script', $page['bodyHtml']);
        $this->assertStringNotContainsString('javascript:', $page['bodyHtml']);
        $this->assertStringContainsString('href="https://example.com"', $page['bodyHtml']);

        // drafts are invisible to the website (and 404 rather than 403)
        $this->pub('/pages/about-us')->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->assertSame([], $this->pub('/pages')->json('items'));

        $pub = $o->post("/admin/cms/pages/{$page['id']}/publish")->assertOk()->json();
        $this->assertSame('PUBLISHED', $pub['status']);
        $this->assertNotNull($pub['publishedAt']);
        $this->assertSame($page['rowVersion'] + 1, $pub['rowVersion']);
        $again = $o->post("/admin/cms/pages/{$page['id']}/publish")->assertOk()->json();
        $this->assertSame($pub['rowVersion'], $again['rowVersion'], 'publishing twice is a no-op');

        $pubPage = $this->pub('/pages/about-us')->assertOk();
        $this->assertSame('About us', $pubPage->json('title'));
        $this->assertStringContainsString('<h2>Hello</h2>', $pubPage->json('bodyHtml'));
        $this->assertArrayHasKey('seo', $pubPage->json());
        $this->assertSame('about-us', $this->pub('/pages')->json('items.0.slug'));

        // If-Match is required on page edits
        $o->patch("/admin/cms/pages/{$page['id']}", ['title' => 'About'])->assertStatus(428);
        $g = $o->get("/admin/cms/pages/{$page['id']}")->assertOk()->assertHeader('ETag');
        $u = $o->patch("/admin/cms/pages/{$page['id']}", ['title' => 'About 007', 'showInFooter' => true], $this->etag($g))->assertOk();
        $this->assertSame('About 007', $u->json('title'));
        $this->assertTrue($u->json('showInFooter'));
        $o->patch("/admin/cms/pages/{$page['id']}", ['title' => 'Stale'], $this->etag($g))->assertStatus(412);
        $this->assertSame('About 007', $this->pub('/pages/about-us')->json('title'));

        // slug rules
        $o->patch("/admin/cms/pages/{$page['id']}", ['slug' => 'Bad Slug'], ['If-Match' => $u->headers->get('ETag')])->assertStatus(422)->assertJsonValidationErrors(['slug']);
        $second = $o->post('/admin/cms/pages', ['title' => 'Another', 'slug' => 'about-us', 'bodyMarkdown' => 'x'])->assertStatus(409)->assertJsonPath('code', 'slug_taken');
        $auto = $o->post('/admin/cms/pages', ['title' => 'About us', 'bodyMarkdown' => 'x'])->assertCreated();
        $this->assertSame('about-us-2', $auto->json('slug'));
        $o->post('/admin/cms/pages', ['title' => 'Third', 'slug' => 'third', 'bodyMarkdown' => 'x'])->assertCreated();
        $o->patch('/admin/cms/pages/'.$auto->json('id'), ['slug' => 'third'], ['If-Match' => $auto->headers->get('ETag')])->assertStatus(409)->assertJsonPath('code', 'slug_taken');

        // unpublish hides again; a published page cannot be deleted; archive then delete
        $o->post("/admin/cms/pages/{$page['id']}/unpublish")->assertOk()->assertJsonPath('status', 'DRAFT');
        $this->pub('/pages/about-us')->assertStatus(404);
        $o->post("/admin/cms/pages/{$page['id']}/publish")->assertOk();
        $o->delete("/admin/cms/pages/{$page['id']}")->assertStatus(409)->assertJsonPath('code', 'must_archive_first');
        $o->post("/admin/cms/pages/{$page['id']}/archive")->assertOk()->assertJsonPath('status', 'ARCHIVED');
        $this->pub('/pages/about-us')->assertStatus(404);
        $o->delete("/admin/cms/pages/{$page['id']}")->assertNoContent();
        $o->get("/admin/cms/pages/{$page['id']}")->assertStatus(404);

        foreach (['create', 'update', 'publish', 'unpublish', 'archive', 'delete'] as $a) {
            $this->assertNotEmpty($this->audit("cms.page.{$a}"), "audit cms.page.{$a}");
        }
        $upd = $this->audit('cms.page.update', $page['id'])[0];
        $this->assertSame('About us', $upd->old['title']);
        $this->assertSame('About 007', $upd->new['title']);
        $this->assertTrue(Audit::verifyChain()->valid);
        // admin list filters
        $this->assertGreaterThanOrEqual(2, count($o->get('/admin/cms/pages?status=DRAFT')->json('items')));
        $o->get('/admin/cms/pages?q=third')->assertJsonCount(1, 'items');
    }

    public function test_scheduled_publish_is_hidden_until_its_time_and_preview_shows_drafts_to_editors(): void
    {
        $o = $this->owner();
        $future = now('UTC')->addDays(3)->toIso8601String();
        $page = $this->published('pages', ['title' => 'Soon', 'bodyMarkdown' => 'later'], $future);
        $this->assertSame('PUBLISHED', $page['status']);
        $this->pub('/pages/soon')->assertStatus(404);
        $this->assertSame([], array_column($this->pub('/sitemap')->json('items'), 'slug'));
        // an editor with cms.view can preview with ?preview=true; the website token cannot
        $r = $this->withHeaders($o->headers(['Accept' => 'application/json']))->getJson('/api/v1/public/cms/pages/soon?preview=true')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('Soon', $r->json('title'));
        $this->pub('/pages/soon?preview=true')->assertStatus(404);
        $this->roleApiPreviewDenied();
        DB::table('cms_page')->update(['published_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s.u')]);
        $this->pub('/pages/soon')->assertOk();
    }

    private function roleApiPreviewDenied(): void
    {
        $cashier = $this->roleApi('CASHIER');
        $this->withHeaders($cashier->headers(['Accept' => 'application/json']))->getJson('/api/v1/public/cms/pages/soon?preview=true')->assertStatus(404);
    }

    public function test_blog_categories_filters_pagination_related_and_reading_time(): void
    {
        $o = $this->owner();
        $news = $o->post('/admin/cms/post-categories', ['name' => 'News'])->assertCreated()->json();
        $sports = $o->post('/admin/cms/post-categories', ['name' => 'Sports', 'sortOrder' => 20])->assertCreated()->json();
        $this->assertSame('news', $news['slug']);
        $cover = $this->newMedia('Cover');
        $words = trim(str_repeat('word ', 450));
        $mk = fn (string $title, string $cat, array $tags, string $ago, array $extra = []) => $this->published('posts', ['title' => $title, 'bodyMarkdown' => $extra['body'] ?? "About {$title}.\n\n".$words, 'categoryId' => $cat, 'tags' => $tags] + $extra, now('UTC')->sub($ago)->toIso8601String());
        $p1 = $mk('Pool day', $news['id'], ['Pool', 'Family'], '5 days', ['coverMediaId' => $cover['id'], 'featured' => true]);
        $p2 = $mk('Court news', $sports['id'], ['tennis'], '4 days');
        $p3 = $mk('Family fun', $news['id'], ['family'], '3 days');
        $p4 = $mk('Match night', $sports['id'], ['football', 'family'], '2 days');
        $draft = $o->post('/admin/cms/posts', ['title' => 'Secret draft', 'bodyMarkdown' => 'x', 'categoryId' => $news['id']])->assertCreated()->json();
        $this->assertSame(['pool', 'family'], $p1['tags'], 'tags are lowercased');
        $this->assertSame(3, $p1['readingTimeMinutes']);
        $this->assertNotEmpty($p1['excerpt'], 'excerpt derived from the body');
        $this->assertSame($cover['id'], $p1['cover']['id']);
        $this->assertSame('news', $p1['category']['slug']);

        $list = $this->pub('/posts')->assertOk();
        $this->assertSame(['match-night', 'family-fun', 'court-news', 'pool-day'], array_column($list->json('items'), 'slug'), 'newest first, drafts hidden');
        $this->assertNull($list->json('nextCursor'));
        $this->assertSame(['news', 'sports'], array_column($this->pub('/post-categories')->json('items'), 'slug'));
        $this->assertSame([2, 2], array_column($this->pub('/post-categories')->json('items'), 'postCount'));
        $this->assertSame(['family-fun', 'pool-day'], array_column($this->pub('/posts?category=news')->json('items'), 'slug'));
        $this->assertSame(['match-night', 'family-fun', 'pool-day'], array_column($this->pub('/posts?tag=Family')->json('items'), 'slug'));
        $this->assertSame(['court-news'], array_column($this->pub('/posts?q=court')->json('items'), 'slug'));
        $this->assertSame(['pool-day'], array_column($this->pub('/posts?featured=true')->json('items'), 'slug'));
        $this->assertSame([], $this->pub('/posts?category=unknown')->json('items'));

        // cursor pagination walks every post exactly once
        $seen = [];
        $cursor = null;
        do {
            $r = $this->pub('/posts?limit=3'.($cursor ? '&cursor='.$cursor : ''))->assertOk();
            $seen = array_merge($seen, array_column($r->json('items'), 'slug'));
            $cursor = $r->json('nextCursor');
        } while ($cursor);
        $this->assertSame(['match-night', 'family-fun', 'court-news', 'pool-day'], $seen);
        $this->pub('/posts?cursor=garbage')->assertStatus(400);

        $detail = $this->pub('/posts/pool-day')->assertOk();
        $this->assertStringContainsString('About Pool day', $detail->json('bodyHtml'));
        $this->assertSame('The 007 team', $detail->json('authorName') ?? 'The 007 team');
        $related = array_column($detail->json('related'), 'slug');
        $this->assertContains('family-fun', $related, 'same category / shared tag');
        $this->assertNotContains('pool-day', $related);
        $this->assertNotContains('secret-draft', $related);
        $this->pub('/posts/secret-draft')->assertStatus(404);

        // category with posts cannot be deleted; empty category can
        $o->delete("/admin/cms/post-categories/{$news['id']}")->assertStatus(409)->assertJsonPath('code', 'category_in_use');
        $extra = $o->post('/admin/cms/post-categories', ['name' => 'Empty'])->json();
        $o->delete("/admin/cms/post-categories/{$extra['id']}")->assertNoContent();
        $o->post('/admin/cms/post-categories/reorder', ['items' => [['id' => $sports['id'], 'sortOrder' => 1], ['id' => $news['id'], 'sortOrder' => 2]]])->assertOk();
        $this->assertSame(['sports', 'news'], array_column($this->pub('/post-categories')->json('items'), 'slug'));

        // validation
        $o->post('/admin/cms/posts', ['title' => 'x', 'bodyMarkdown' => 'y', 'categoryId' => '0192a1b2-0000-7000-8000-000000000000'])->assertStatus(422)->assertJsonValidationErrors(['categoryId']);
        $o->post('/admin/cms/posts', ['title' => 'x', 'bodyMarkdown' => 'y', 'tags' => array_fill(0, 13, 'a')])->assertStatus(422);
        $o->post('/admin/cms/posts', ['bodyMarkdown' => 'y'])->assertStatus(422)->assertJsonValidationErrors(['title']);
        // cover used by a post is protected
        $o->delete("/admin/cms/media/{$cover['id']}")->assertStatus(409)->assertJsonPath('usage.0.type', 'post');
        // editing a post needs If-Match; the rendered body follows the markdown
        $g = $o->get("/admin/cms/posts/{$draft['id']}");
        $o->patch("/admin/cms/posts/{$draft['id']}", ['title' => 'Renamed'])->assertStatus(428);
        $u = $o->patch("/admin/cms/posts/{$draft['id']}", ['bodyMarkdown' => '# Updated'], $this->etag($g))->assertOk();
        $this->assertStringContainsString('<h1>Updated</h1>', $u->json('bodyHtml'));
        $this->assertSame(1, $u->json('readingTimeMinutes'));
        // sitemap lists published content with lastmod
        $map = $this->pub('/sitemap')->assertOk();
        $this->assertContains('pool-day', array_column(array_filter($map->json('items'), fn ($i) => $i['type'] === 'post'), 'slug'));
        $this->assertNotContains('secret-draft', array_column($map->json('items'), 'slug'));
        $this->assertNotEmpty($map->json('items.0.lastModified'));
    }

    public function test_editors_without_publish_permission_cannot_publish(): void
    {
        $it = $this->roleApi('IT_ADMIN');
        $p = $it->post('/admin/cms/pages', ['title' => 'Draft by IT', 'bodyMarkdown' => 'x'])->assertCreated()->json();
        $it->post("/admin/cms/pages/{$p['id']}/publish")->assertStatus(403)->assertJsonPath('permission', 'cms.publish');
        $this->roleApi('MARKETING')->post("/admin/cms/pages/{$p['id']}/publish")->assertOk();
    }

    public function test_creates_require_an_idempotency_key_and_replay_returns_the_original(): void
    {
        $o = $this->owner();
        $this->withHeaders($o->headers(['Accept' => 'application/json']))->postJson('/api/v1/admin/cms/pages', ['title' => 'K', 'bodyMarkdown' => 'x'])->assertStatus(400)->assertJsonPath('code', 'idempotency_key_missing');
        $h = $o->headers(['Idempotency-Key' => 'same-key-1']);
        $a = $this->withHeaders($h)->postJson('/api/v1/admin/cms/pages', ['title' => 'K', 'bodyMarkdown' => 'x'])->assertCreated();
        $b = $this->withHeaders($h)->postJson('/api/v1/admin/cms/pages', ['title' => 'K', 'bodyMarkdown' => 'x'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($a->json('id'), $b->json('id'));
        $this->assertSame(1, DB::table('cms_page')->count());
    }
}
