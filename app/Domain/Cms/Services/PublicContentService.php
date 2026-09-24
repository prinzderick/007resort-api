<?php

namespace App\Domain\Cms\Services;

use App\Domain\Cms\Support\Cms;
use App\Domain\Cms\Support\HomeSectionTypes;
use App\Domain\Cms\Support\Markdown;
use App\Domain\Cms\Support\MediaResolver;
use App\Domain\Cms\Support\Recurrence;
use App\Domain\Cms\Support\Rows;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Read side of the website API. Everything here honours "PUBLISHED and published_at <= now" unless `$preview` (staff with cms.view). */
class PublicContentService
{
    public function __construct(private readonly MediaResolver $media, private readonly SettingsService $settings) {}

    private function visible(Builder $q, bool $preview): Builder
    {
        return $preview ? $q : $q->where('status', 'PUBLISHED')->where('published_at', '<=', Rows::now());
    }

    /** @return array<string, mixed> */
    public function site(): array
    {
        return $this->settings->publicSite();
    }

    /** @return array<string, mixed> */
    public function home(): array
    {
        $rows = DB::table('cms_home_section')->where('is_enabled', 1)->orderBy('sort_order')->orderBy('id')->get();
        $payloads = $rows->map(fn ($r) => Rows::jsonObj($r->payload))->all();
        $this->media->load(MediaResolver::collect($payloads));
        $byType = array_fill_keys(HomeSectionTypes::TYPES, []);
        $sections = [];
        $latest = '';
        foreach ($rows as $i => $r) {
            $p = $this->media->expand($payloads[$i]);
            if ($r->type === 'FAQ') {
                $p['answerHtml'] = Markdown::html($p['answer'] ?? '');
            }
            $s = ['id' => Rows::id($r->id), 'type' => $r->type, 'sortOrder' => (int) $r->sort_order] + $p;
            $sections[] = $s;
            $byType[$r->type][] = $s;
            $latest = max($latest, $r->updated_at);
        }

        return ['sections' => $sections, 'byType' => $byType, 'updatedAt' => $latest === '' ? null : Rows::iso($latest)];
    }

    /** @return array<string, mixed> */
    public function pages(bool $preview): array
    {
        $rows = $this->visible(DB::table('cms_page'), $preview)->orderBy('sort_order')->orderBy('title')->get();

        return ['items' => $rows->map(fn ($r) => ['slug' => $r->slug, 'title' => $r->title, 'showInFooter' => (bool) $r->show_in_footer, 'updatedAt' => Rows::iso($r->updated_at)])->all(), 'nextCursor' => null];
    }

    /** @return array<string, mixed> */
    public function page(string $slug, bool $preview): array
    {
        $r = $this->visible(DB::table('cms_page')->where('slug', $slug), $preview)->first() ?? throw ApiProblem::notFound('not_found', 'Page not found.');
        $this->media->load([Rows::id($r->hero_media_id), Rows::id($r->seo_og_media_id)]);

        return [
            'id' => Rows::id($r->id), 'slug' => $r->slug, 'title' => $r->title, 'subtitle' => $r->subtitle, 'hero' => $this->media->public(Rows::id($r->hero_media_id)),
            'bodyHtml' => $r->body_html, 'bodyMarkdown' => $r->body_markdown, 'seo' => $this->seo($r), 'showInFooter' => (bool) $r->show_in_footer,
            'publishedAt' => Rows::iso($r->published_at), 'updatedAt' => Rows::iso($r->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    private function seo(object $r): array
    {
        return ['title' => $r->seo_title, 'description' => $r->seo_description, 'ogImage' => $this->media->public(Rows::id($r->seo_og_media_id))];
    }

    // ---- blog ----

    /** @return array<string, mixed> */
    public function posts(Request $request, bool $preview): array
    {
        $q = $this->visible(DB::table('cms_post'), $preview);
        if (($slug = (string) $request->query('category')) !== '') {
            $cat = DB::table('cms_post_category')->where('slug', $slug)->value('id');
            $q->where('category_id', $cat ?? Ids::toBinary('00000000-0000-0000-0000-000000000000'));
        }
        if (($tag = trim((string) $request->query('tag'))) !== '') {
            $q->whereJsonContains('tags', mb_strtolower($tag));
        }
        if (($term = trim((string) $request->query('q'))) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $q->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('excerpt', 'like', $like)->orWhere('body_markdown', 'like', $like));
        }
        if ($request->query('featured') !== null && filter_var($request->query('featured'), FILTER_VALIDATE_BOOLEAN)) {
            $q->where('is_featured', 1);
        }
        $page = CursorPage::paginate($q, $request, 'published_at', 'desc', 12, 50);
        $this->preloadPosts($page->items->all());

        return $page->toArray(fn ($r) => $this->postItem($r));
    }

    /** @var array<string, array{slug: string, name: string}> */
    private array $cats = [];

    /** @param list<object> $rows */
    private function preloadPosts(array $rows): void
    {
        $this->media->load(array_map(fn ($r) => Rows::id($r->cover_media_id), $rows));
        $ids = array_values(array_unique(array_filter(array_map(fn ($r) => $r->category_id, $rows))));
        if ($ids !== []) {
            foreach (DB::table('cms_post_category')->whereIn('id', $ids)->get(['id', 'slug', 'name']) as $c) {
                $this->cats[Rows::id($c->id)] = ['slug' => $c->slug, 'name' => $c->name];
            }
        }
    }

    /** @return array<string, mixed> */
    private function postItem(object $r): array
    {
        return [
            'id' => Rows::id($r->id), 'slug' => $r->slug, 'title' => $r->title, 'excerpt' => $r->excerpt, 'cover' => $this->media->public(Rows::id($r->cover_media_id)), 'authorName' => $r->author_name,
            'category' => $r->category_id ? ($this->cats[Rows::id($r->category_id)] ?? null) : null, 'tags' => Rows::json($r->tags), 'readingTimeMinutes' => (int) $r->reading_time_minutes,
            'featured' => (bool) $r->is_featured, 'publishedAt' => Rows::iso($r->published_at),
        ];
    }

    /** @return array<string, mixed> */
    public function post(string $slug, bool $preview): array
    {
        $r = $this->visible(DB::table('cms_post')->where('slug', $slug), $preview)->first() ?? throw ApiProblem::notFound('not_found', 'Post not found.');
        $related = $this->visible(DB::table('cms_post')->where('id', '!=', $r->id), $preview)
            ->where(function ($w) use ($r) {
                $w->when($r->category_id !== null, fn ($x) => $x->orWhere('category_id', $r->category_id));
                $tags = Rows::json($r->tags);
                $w->when($tags !== [], fn ($x) => $x->orWhereJsonOverlaps('tags', $tags));
                if ($r->category_id === null && $tags === []) {
                    $w->whereRaw('1 = 0');
                }
            })->orderByDesc('published_at')->limit(3)->get()->all();
        $this->preloadPosts([$r, ...$related]);

        return $this->postItem($r) + [
            'bodyHtml' => $r->body_html, 'bodyMarkdown' => $r->body_markdown, 'seo' => $this->seo($r), 'updatedAt' => Rows::iso($r->updated_at),
            'related' => array_map(fn ($x) => $this->postItem($x), $related),
        ];
    }

    /** @return array<string, mixed> */
    public function postCategories(bool $all, bool $preview): array
    {
        $counts = $this->visible(DB::table('cms_post'), $preview)->whereNotNull('category_id')->selectRaw('category_id, COUNT(*) n')->groupBy('category_id')->pluck('n', 'category_id');
        $items = [];
        foreach (DB::table('cms_post_category')->orderBy('sort_order')->orderBy('name')->get() as $c) {
            $n = (int) ($counts[$c->id] ?? 0);
            if ($n > 0 || $all) {
                $items[] = ['id' => Rows::id($c->id), 'slug' => $c->slug, 'name' => $c->name, 'description' => $c->description, 'postCount' => $n];
            }
        }

        return ['items' => $items, 'nextCursor' => null];
    }

    // ---- events ----

    /** @return array<string, mixed> */
    private function eventItem(object $r, ?array $occ = null): array
    {
        $tz = Cms::tz();
        $s = $occ ? $occ['start'] : Rows::carbon($r->starts_at);
        $e = $occ ? $occ['end'] : Rows::carbon($r->ends_at);
        $ticket = ($r->ticket_url || $r->ticket_product_id || $r->facility_id)
            ? ['url' => $r->ticket_url, 'productId' => Rows::id($r->ticket_product_id), 'facilityId' => Rows::id($r->facility_id)] : null;

        return [
            'id' => Rows::id($r->id), 'slug' => $r->slug, 'title' => $r->title, 'summary' => $r->summary, 'cover' => $this->media->public(Rows::id($r->cover_media_id)), 'category' => $r->category,
            'startsAt' => $s->utc()->format('Y-m-d\TH:i:s.v\Z'), 'endsAt' => $e->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'startsAtLocal' => $s->setTimezone($tz)->format('Y-m-d\TH:i:sP'), 'endsAtLocal' => $e->setTimezone($tz)->format('Y-m-d\TH:i:sP'), 'timezone' => $tz,
            'venue' => ['label' => $r->venue_label, 'facilityId' => Rows::id($r->facility_id)], 'priceText' => $r->price_text, 'capacity' => $r->capacity === null ? null : (int) $r->capacity,
            'ticket' => $ticket, 'recurrence' => ['type' => $r->recurrence, 'until' => $r->recurrence_until], 'isRecurring' => $r->recurrence === 'WEEKLY',
            'occurrenceKey' => $r->slug.'@'.$s->utc()->format('Y-m-d\TH:i:s\Z'), 'featured' => (bool) $r->is_featured, 'publishedAt' => Rows::iso($r->published_at),
        ];
    }

    /** @return array<string, mixed> */
    public function events(Request $request, bool $preview): array
    {
        $now = CarbonImmutable::now('UTC');
        $q = $this->visible(DB::table('cms_event'), $preview);
        if (in_array($c = strtoupper((string) $request->query('category')), Cms::EVENT_CATEGORIES, true)) {
            $q->where('category', $c);
        }
        if ($request->query('featured') !== null && filter_var($request->query('featured'), FILTER_VALIDATE_BOOLEAN)) {
            $q->where('is_featured', 1);
        }
        $limit = max(1, min(50, (int) $request->query('limit', 12) ?: 12));
        $upcoming = $request->query('upcoming');
        $today = $now->setTimezone(Cms::tz())->format('Y-m-d');

        if ($upcoming !== null && filter_var($upcoming, FILTER_VALIDATE_BOOLEAN)) {
            $q->where(fn ($w) => $w->where('ends_at', '>=', Rows::db($now))->orWhere(fn ($w2) => $w2->where('recurrence', 'WEEKLY')->where(fn ($w3) => $w3->whereNull('recurrence_until')->orWhere('recurrence_until', '>=', $today))));
            $per = max(1, min(12, (int) $request->query('occurrences', 4) ?: 4));
            $flat = [];
            foreach ($q->orderBy('starts_at')->orderBy('id')->limit(500)->get() as $r) {
                foreach (Recurrence::next($r, $now, $r->recurrence === 'WEEKLY' ? $per : 1) as $occ) {
                    $flat[] = [$occ['start']->format('Y-m-d\TH:i:s.u'), Rows::id($r->id), $r, $occ];
                }
            }
            usort($flat, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
            if (($cursor = (string) $request->query('cursor')) !== '') {
                $c = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);
                if (! is_array($c) || ! isset($c['s'], $c['i'])) {
                    throw ApiProblem::badRequest('validation_failed', 'The pagination cursor is invalid.', ['errors' => ['cursor' => ['Invalid cursor.']]]);
                }
                $flat = array_values(array_filter($flat, fn ($x) => [$x[0], $x[1]] > [$c['s'], $c['i']]));
            }
            $more = count($flat) > $limit;
            $slice = array_slice($flat, 0, $limit);
            $this->media->load(array_map(fn ($x) => Rows::id($x[2]->cover_media_id), $slice));
            $next = $more ? rtrim(strtr(base64_encode(json_encode(['s' => end($slice)[0], 'i' => end($slice)[1]])), '+/', '-_'), '=') : null;

            return ['items' => array_map(fn ($x) => $this->eventItem($x[2], $x[3]), $slice), 'nextCursor' => $next];
        }
        if ($upcoming !== null) { // upcoming=false: ended events only
            $q->where('ends_at', '<', Rows::db($now))->where(fn ($w) => $w->where('recurrence', 'NONE')->orWhere('recurrence_until', '<', $today));
        }
        $page = CursorPage::paginate($q, $request, 'starts_at', 'desc', 12, 50);
        $this->media->load($page->items->map(fn ($r) => Rows::id($r->cover_media_id))->all());

        return $page->toArray(fn ($r) => $this->eventItem($r));
    }

    /** @return array<string, mixed> */
    public function event(string $slug, bool $preview): array
    {
        $r = $this->visible(DB::table('cms_event')->where('slug', $slug), $preview)->first() ?? throw ApiProblem::notFound('not_found', 'Event not found.');
        $this->media->load([Rows::id($r->cover_media_id), Rows::id($r->seo_og_media_id)]);
        $tz = Cms::tz();
        $occ = Recurrence::next($r, CarbonImmutable::now('UTC'), 8);
        $fmt = fn ($o) => ['startsAt' => $o['start']->format('Y-m-d\TH:i:s.v\Z'), 'endsAt' => $o['end']->format('Y-m-d\TH:i:s.v\Z'),
            'startsAtLocal' => $o['start']->setTimezone($tz)->format('Y-m-d\TH:i:sP'), 'endsAtLocal' => $o['end']->setTimezone($tz)->format('Y-m-d\TH:i:sP')];

        return $this->eventItem($r, $occ[0] ?? null) + [
            'bodyHtml' => $r->body_html ?? '', 'bodyMarkdown' => $r->body_markdown, 'seo' => $this->seo($r), 'updatedAt' => Rows::iso($r->updated_at), 'nextOccurrences' => array_map($fmt, $occ),
        ];
    }

    // ---- gallery ----

    /** @return array<string, mixed> */
    public function albums(bool $preview): array
    {
        $rows = $this->visible(DB::table('cms_gallery_album'), $preview)->orderBy('sort_order')->orderBy('id')->get();
        $counts = DB::table('cms_gallery_item')->selectRaw('album_id, COUNT(*) n')->groupBy('album_id')->pluck('n', 'album_id');
        $this->media->load($rows->map(fn ($r) => Rows::id($r->cover_media_id))->all());

        return ['items' => $rows->map(fn ($r) => $this->albumItem($r, (int) ($counts[$r->id] ?? 0)))->all(), 'nextCursor' => null];
    }

    /** @return array<string, mixed> */
    private function albumItem(object $r, int $count): array
    {
        return ['id' => Rows::id($r->id), 'slug' => $r->slug, 'title' => $r->title, 'description' => $r->description, 'cover' => $this->media->public(Rows::id($r->cover_media_id)), 'itemCount' => $count, 'sortOrder' => (int) $r->sort_order];
    }

    /** @return array<string, mixed> */
    public function album(string $slug, bool $preview): array
    {
        $a = $this->visible(DB::table('cms_gallery_album')->where('slug', $slug), $preview)->first() ?? throw ApiProblem::notFound('not_found', 'Album not found.');
        $items = DB::table('cms_gallery_item')->where('album_id', $a->id)->orderBy('sort_order')->orderBy('id')->limit(500)->get();
        $this->media->load([...$items->map(fn ($i) => Rows::id($i->media_id))->all(), Rows::id($a->cover_media_id)]);

        return $this->albumItem($a, $items->count()) + ['items' => $items->map(function ($i) {
            $m = $this->media->public(Rows::id($i->media_id));

            return ['id' => Rows::id($i->id), 'media' => $m, 'caption' => $i->caption, 'alt' => $i->alt_text ?: ($m['alt'] ?? ''), 'tags' => Rows::json($i->tags), 'category' => $i->category, 'featured' => (bool) $i->is_featured, 'sortOrder' => (int) $i->sort_order];
        })->all()];
    }

    /** @return array<string, mixed> */
    public function sitemap(): array
    {
        $items = [];
        foreach ([['page', 'cms_page'], ['post', 'cms_post'], ['event', 'cms_event'], ['album', 'cms_gallery_album']] as [$type, $table]) {
            foreach ($this->visible(DB::table($table), false)->orderBy('slug')->get(['slug', 'updated_at']) as $r) {
                $items[] = ['type' => $type, 'slug' => $r->slug, 'lastModified' => Rows::iso($r->updated_at)];
            }
        }

        return ['generatedAt' => Rows::iso(Rows::now()), 'items' => $items];
    }
}
