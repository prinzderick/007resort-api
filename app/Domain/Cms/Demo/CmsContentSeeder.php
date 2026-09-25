<?php

namespace App\Domain\Cms\Demo;

use App\Domain\Cms\Resources\ContentResource;
use App\Domain\Cms\Resources\ResourceRegistry;
use App\Domain\Cms\Services\ContentService;
use App\Domain\Cms\Services\GalleryService;
use App\Domain\Cms\Services\MediaService;
use App\Domain\Cms\Services\SettingsService;
use App\Domain\Cms\Support\Rows;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Believable demo website content, idempotent (rows are matched by slug / natural key and never duplicated or overwritten once edited).
 * Photos come from database/seeders/stock/ (manifest.json + credits in CREDITS.md; override with CMS_STOCK_DIR) and are imported through the
 * real MediaService, so variants / dominant colour / EXIF stripping apply. Without photos the content is still seeded (image slots stay empty).
 */
class CmsContentSeeder
{
    /** slug, title, category, summary, cover key, when, start hour, hours, venue, price text, capacity, recurrence, featured, body */
    private const EVENTS = [
        ['friday-night-live', 'Friday Night Live', 'MUSIC', 'Live band, grill and cocktails on the poolside deck.', 'events-01', 'last friday', 19, 4, 'Poolside Deck', 'From NGN 5,000', 150, 'WEEKLY', true,
            "Every Friday the deck turns into a live music venue.\n\n- Live band from 8pm\n- Grill open until midnight\n- Cabanas for groups"],
        ['sunday-sunset-yoga', 'Sunday Sunset Yoga', 'WELLNESS', 'A gentle outdoor class as the sun goes down.', 'events-05', 'last sunday', 17, 1, 'Palm Lawn', 'NGN 3,000', 30, 'WEEKLY', false,
            'Unwind before the week starts. Mats provided; bring water and a towel.'],
        ['saturday-five-a-side-league', 'Saturday Five-a-side League', 'SPORT', 'Weekly league on the floodlit pitch. Teams of five, prizes for the top three.', 'football-02', 'last saturday', 16, 5, 'Sports Arena', 'NGN 25,000 per team', 96, 'WEEKLY', false,
            'Register your team of five to join the league. Friends, colleagues and student or staff teams are all welcome, and referees and scorekeepers are provided.'],
        ['champions-league-match-night', 'Match Night on the Big Screens', 'SPORT', 'Watch the big game on giant screens with the grill going.', 'events-03', '+9 days', 20, 3, 'Indoor Club', 'Free entry', 200, 'NONE', true,
            'Big screens, cold drinks and a full house. Arrive early for the best seats, and come straight from campus or the office.'],
        ['poolside-dj-party', 'Poolside DJ Party', 'PARTY', 'Resident DJs, confetti and a pool that never closes.', 'events-02', '+16 days', 21, 6, 'Resort Pool', 'From NGN 8,000', 300, 'NONE', true,
            'Dress light and bring your dancing shoes. Cabana bookings open on the events page.'],
        ['kids-holiday-camp', 'Kids Holiday Camp', 'OTHER', 'Games, swimming lessons and crafts for ages 6 to 12.', 'events-06', '+30 days', 9, 6, 'Palm Lawn', 'NGN 12,000 per day', 60, 'NONE', false,
            'A supervised day of fun for children. Lunch and snacks included.'],
        ['jollof-and-grill-festival', 'Jollof and Grill Festival', 'FOOD', 'Chefs compete, guests vote. Tasting tickets include five plates.', 'dining-03', '-12 days', 12, 8, 'Restaurant Terrace', 'NGN 10,000', 250, 'NONE', false,
            'Our annual food festival brought together six chefs and a very hungry crowd.'],
        ['new-year-countdown-party', 'Founders Day Pool Party', 'PARTY', 'Our past anniversary celebration, with live sets and fireworks.', 'events-07', '-35 days', 20, 6, 'Resort Pool', 'NGN 7,500', 400, 'NONE', false,
            'Thanks to everyone who celebrated with us.'],
    ];

    /** slug, title, description, photo keys (first = cover) */
    private const ALBUMS = [
        ['pool-and-spa', 'Pool & Spa', 'Loungers, palms and quiet corners to reset.', ['pool-01', 'pool-02', 'pool-04', 'pool-06', 'spa-01', 'spa-03', 'spa-04', 'spa-05']],
        ['courts-and-pitches', 'Courts & Pitches', 'Floodlit games, clay courts and five-a-side.', ['football-02', 'basketball-02', 'basketball-01', 'football-01', 'tennis-02', 'tennis-03', 'padel-01', 'lifestyle-05']],
        ['food-and-drinks', 'Food & Drinks', 'From the grill, the bar and the brunch table.', ['dining-03', 'dining-04', 'dining-05', 'dining-06', 'dining-08', 'dining-09']],
        ['nights-and-events', 'Nights & Events', 'Live music, match nights and celebrations.', ['events-01', 'events-02', 'events-03', 'events-07', 'events-05', 'events-06', 'gallery-03', 'gallery-04']],
        ['friends-and-family', 'Friends & Family', 'Weekends out with the people who matter.', ['lifestyle-01', 'lifestyle-02', 'lifestyle-03', 'lifestyle-04', 'pool-06', 'hero-02', 'gallery-01']],
    ];

    /** @var array<string, string> manifest file => media id */
    private array $media = [];

    /** @var callable */
    private $say;

    public function __construct(
        private readonly ContentService $content,
        private readonly MediaService $mediaService,
        private readonly SettingsService $settings,
        private readonly GalleryService $gallery,
    ) {}

    public function run(callable $say): void
    {
        $this->say = $say;
        $this->settings->ensureDefaults();
        $this->importPhotos();
        $this->seedSettings();
        $this->seedHome();
        $this->seedPages();
        $this->seedBlog();
        $this->seedEvents();
        $this->seedGallery();
        ($this->say)('website CMS content ready (settings, home, pages, blog, events, gallery)');
    }

    /**
     * `r007:cms-seed --refresh-media`: bring an ALREADY seeded database in line with the current stock folder without touching any text.
     * Imports photos that are new, repoints every reference (settings, home sections, pages, posts, events, albums, gallery items) from a stale
     * seeded photo (same key prefix such as `hero-02-`, different file name) to its replacement, deletes the stale media once unreferenced,
     * then fills blanks (testimonial avatars, missing album photos, events without a cover). Idempotent; human-edited media are never stale
     * because only files whose original name starts with a manifest key are replaced.
     */
    public function refreshMedia(callable $say): void
    {
        $this->say = $say;
        $this->importPhotos();
        $replaced = 0;
        $removed = 0;
        foreach ($this->media as $file => $newId) {
            $key = $this->key($file);
            $stale = DB::table('cms_media')->where('original_name', 'like', $key.'-%')->where('original_name', '!=', $file)->get(['id', 'original_name']);
            foreach ($stale as $old) {
                if (isset($this->media[$old->original_name])) {
                    continue; // still part of the current stock set (two files sharing a key)
                }
                $oldId = Rows::id($old->id);
                $this->repoint($oldId, $newId);
                $replaced++;
                try {
                    $this->mediaService->delete($oldId);
                    $removed++;
                } catch (\Throwable $e) {
                    ($this->say)("kept {$old->original_name} (still referenced): ".$e->getMessage());
                }
            }
        }
        $this->fillGaps();
        ($this->say)("media refreshed: {$replaced} replaced, {$removed} stale photos removed");
    }

    private function key(string $file): string
    {
        $p = explode('-', pathinfo($file, PATHINFO_FILENAME), 3);

        return $p[0].'-'.($p[1] ?? '');
    }

    /** Point every reference to $old at $new (gallery items that would duplicate an album photo are dropped instead). */
    private function repoint(string $old, string $new): void
    {
        [$ob, $nb] = [Ids::toBinary($old), Ids::toBinary($new)];
        foreach (['cms_page' => ['hero_media_id', 'seo_og_media_id'], 'cms_post' => ['cover_media_id', 'seo_og_media_id'], 'cms_event' => ['cover_media_id', 'seo_og_media_id'], 'cms_gallery_album' => ['cover_media_id']] as $table => $cols) {
            foreach ($cols as $col) {
                DB::table($table)->where($col, $ob)->update([$col => $nb, 'updated_at' => Rows::now()]);
            }
        }
        foreach (DB::table('cms_gallery_item')->where('media_id', $ob)->get(['id', 'album_id']) as $item) {
            if (DB::table('cms_gallery_item')->where('album_id', $item->album_id)->where('media_id', $nb)->exists()) {
                DB::table('cms_gallery_item')->where('id', $item->id)->delete();
            } else {
                DB::table('cms_gallery_item')->where('id', $item->id)->update(['media_id' => $nb, 'updated_at' => Rows::now()]);
            }
        }
        foreach (['cms_home_section' => 'payload', 'cms_setting' => 'value'] as $table => $col) {
            foreach (DB::table($table)->where($col, 'like', '%'.$old.'%')->get(['id', $col, 'row_version']) as $r) {
                DB::table($table)->where('id', $r->id)->update([$col => str_replace($old, $new, (string) $r->{$col}), 'row_version' => $r->row_version + 1, 'updated_at' => Rows::now()]);
            }
        }
    }

    /** Blanks a newer stock set can fill: testimonial avatars, album photos not yet in the album, events without a cover. */
    private function fillGaps(): void
    {
        foreach (DB::table('cms_home_section')->where('type', 'TESTIMONIAL')->get() as $r) {
            $p = Rows::jsonObj($r->payload);
            $avatar = $this->avatarFor((string) ($p['name'] ?? ''));
            if (empty($p['avatarMediaId']) && $avatar !== null) {
                $p['avatarMediaId'] = $avatar;
                DB::table('cms_home_section')->where('id', $r->id)->update(['payload' => Rows::enc($p), 'row_version' => $r->row_version + 1, 'updated_at' => Rows::now()]);
            }
        }
        foreach (self::ALBUMS as [$slug, , , $files]) {
            $album = DB::table('cms_gallery_album')->where('slug', $slug)->value('id');
            if ($album === null) {
                continue;
            }
            $have = DB::table('cms_gallery_item')->where('album_id', $album)->pluck('media_id')->map(fn ($b) => Rows::id($b))->all();
            $order = (int) DB::table('cms_gallery_item')->where('album_id', $album)->max('sort_order');
            $items = [];
            foreach ($files as $f) {
                $m = $this->img($f);
                if ($m !== null && ! in_array($m, $have, true)) {
                    $items[] = ['mediaId' => $m, 'caption' => null, 'category' => explode('-', $f)[0], 'featured' => false, 'sortOrder' => $order += 10];
                }
            }
            if ($items !== []) {
                $this->gallery->add(Rows::id($album), ['items' => $items]);
            }
        }
        foreach (self::EVENTS as $e) {
            $cover = $this->img($e[4]);
            if ($cover !== null) {
                DB::table('cms_event')->where('slug', $e[0])->whereNull('cover_media_id')->update(['cover_media_id' => Ids::toBinary($cover), 'updated_at' => Rows::now()]);
            }
        }
    }

    // ---- media ----

    private function stockDir(): string
    {
        return (string) (config('cms.stock_dir') ?: database_path('seeders/stock'));
    }

    private function importPhotos(): void
    {
        $dir = $this->stockDir();
        $manifest = [];
        if (is_file($dir.'/manifest.json')) {
            $manifest = (array) json_decode((string) file_get_contents($dir.'/manifest.json'), true);
        } else {
            foreach (glob($dir.'/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $f) {
                $manifest[] = ['file' => basename($f), 'alt' => ucfirst(str_replace(['-', '_'], ' ', pathinfo($f, PATHINFO_FILENAME))), 'credit' => null, 'sourceUrl' => null, 'tags' => [], 'category' => 'misc'];
            }
        }
        if ($manifest === []) {
            ($this->say)('no stock photos found in '.$dir.'; seeding text content only');

            return;
        }
        $n = 0;
        foreach ($manifest as $e) {
            $path = $dir.'/'.$e['file'];
            if (! is_file($path)) {
                continue;
            }
            $existing = DB::table('cms_media')->where('original_name', $e['file'])->value('id');
            if ($existing !== null) {
                $this->media[$e['file']] = Rows::id($existing);

                continue;
            }
            try {
                $m = $this->mediaService->ingest($path, $e['file'], [
                    'alt' => $e['alt'] ?? '', 'credit' => ($e['credit'] ?? null) ? 'Photo: '.$e['credit'].' / '.($e['provider'] ?? 'Unsplash') : null, 'sourceUrl' => $e['sourceUrl'] ?? null,
                    'tags' => array_values(array_unique(array_merge([$e['category'] ?? 'misc'], $e['tags'] ?? []))),
                ]);
            } catch (\Throwable $ex) {
                ($this->say)('photo skipped ('.$e['file'].'): '.$ex->getMessage());

                continue;
            }
            $this->media[$e['file']] = $m['id'];
            $n++;
        }
        ($this->say)("photos: {$n} imported, ".(count($this->media) - $n).' already present');
    }

    /** Media id of the first stock file starting with the prefix (e.g. `hero-01`). */
    private function img(string $prefix): ?string
    {
        foreach ($this->media as $file => $id) {
            if (str_starts_with($file, $prefix.'-') || $file === $prefix) {
                return $id;
            }
        }

        return null;
    }

    private const AVATARS = ['Chinedu Okafor' => 'avatar-01', 'Amaka Eze' => 'avatar-02', 'Tonye Briggs' => 'avatar-03', 'Ifeoma Nwosu' => 'avatar-04', 'Daniel Adeyemi' => 'avatar-05'];

    private function avatarFor(string $name): ?string
    {
        return isset(self::AVATARS[$name]) ? $this->img(self::AVATARS[$name]) : null;
    }

    // ---- settings ----

    private function seedSettings(): void
    {
        $og = DB::table('cms_setting')->where('grp', 'seo')->first();
        if ($og && (int) $og->row_version === 1 && ($id = $this->img('hero-01'))) {
            $v = Rows::jsonObj($og->value);
            $v['ogImageMediaId'] = $id;
            DB::table('cms_setting')->where('id', $og->id)->update(['value' => Rows::enc($v), 'row_version' => 2, 'updated_at' => Rows::now()]);
        }
        $ann = DB::table('cms_setting')->where('grp', 'announcement')->first();
        if ($ann && (int) $ann->row_version === 1) {
            DB::table('cms_setting')->where('id', $ann->id)->update([
                'value' => Rows::enc(['enabled' => true, 'text' => 'Friday Night Live returns every week: live band, grill and cocktails at the poolside deck.', 'link' => '/events', 'tone' => 'PROMO']),
                'row_version' => 2, 'updated_at' => Rows::now(),
            ]);
        }
    }

    // ---- generic helpers ----

    private function res(string $key): ContentResource
    {
        return ResourceRegistry::get($key);
    }

    /** Create (once, matched by slug) and publish. @return string|null id */
    private function slugged(string $key, string $slug, array $data, ?CarbonImmutable $publishedAt = null, bool $publish = true): ?string
    {
        $res = $this->res($key);
        $existing = DB::table($res->table())->where('slug', $slug)->value('id');
        if ($existing !== null) {
            return Rows::id($existing);
        }
        try {
            $d = $this->content->validate($res, ['slug' => $slug] + $data, true);
            $row = $this->content->create($res, $d);
        } catch (ValidationException $e) {
            ($this->say)("skipped {$key}/{$slug}: ".json_encode($e->errors()));

            return null;
        }
        if ($publish) {
            $this->content->transition($res, $row['id'], 'publish', ($publishedAt ?? CarbonImmutable::now('UTC')->subDay())->toIso8601String());
        }

        return $row['id'];
    }

    /** @param array<string, mixed> $payload */
    private function section(string $type, string $keyField, array $payload, int $order): void
    {
        $key = $payload[$keyField];
        if (DB::table('cms_home_section')->where('type', $type)->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.{$keyField}')) = ?", [$key])->exists()) {
            return;
        }
        $res = $this->res('home-sections');
        try {
            $d = $this->content->validate($res, ['type' => $type, 'payload' => $payload, 'sortOrder' => $order], true);
            $row = $this->content->create($res, $d);
        } catch (ValidationException $e) {
            ($this->say)("skipped {$type} '{$key}': ".json_encode($e->errors()));

            return;
        }
        $this->content->setEnabled($res, $row['id'], true);
    }

    // ---- home ----

    private function seedHome(): void
    {
        $slides = [
            ['Play hard. Splash harder.', 'Floodlit courts, a resort pool and a kitchen that never slows down, close to the Federal University Otueke in Bayelsa State.', 'hero-01', 'Book a court', '/book', 'LEFT'],
            ['Your weekend, sorted.', 'Day passes, memberships and group bookings for families, students, teams and friends, near the Federal University Otueke.', 'hero-02', 'Get tickets', '/tickets', 'CENTER'],
            ['Nights worth staying up for.', 'Live bands, match nights and poolside parties, every week, in Otueke, Bayelsa.', 'hero-06', 'See what is on', '/events', 'LEFT'],
        ];
        foreach ($slides as $i => [$h, $sub, $img, $cta, $link, $al]) {
            $this->section('HERO_SLIDE', 'headline', ['headline' => $h, 'subheadline' => $sub, 'mediaId' => $this->img($img), 'ctaLabel' => $cta, 'ctaLink' => $link, 'alignment' => $al], 10 * ($i + 1));
        }
        $hl = [
            ['Sports Arena', 'Football pitch, basketball and tennis courts under floodlights. Book by the hour or bring the whole team.', 'From NGN 15,000 / hour', 'football-02', '/book', 'play'],
            ['Racket Sports', 'Clay and hard tennis courts, plus badminton and table tennis, with rackets and balls to hire.', 'From NGN 6,000 / hour', 'tennis-02', '/book', 'play'],
            ['The Resort Pool', 'Loungers, palms and a big blue pool. Day tickets for adults and kids, cabanas for groups.', 'Day pass from NGN 5,000', 'pool-01', '/tickets', 'splash'],
            ['Spa & Massage', 'Hot stone, deep tissue and facials in a quiet, candle-lit spa with sauna and steam.', 'Treatments from NGN 18,000', 'spa-01', '/spa', 'reset'],
            ['The Restaurant', 'Flame-grilled meats, smoky jollof, fresh fish and a brunch spread on weekends.', 'Mains from NGN 4,500', 'dining-03', '/dining', 'feast'],
            ['Poolside Bar', 'Cold beers, cocktails and shots by the water, with a DJ on event nights.', 'Cocktails from NGN 3,500', 'dining-05', '/dining', 'feast'],
        ];
        foreach ($hl as $i => [$t, $b, $price, $img, $link, $cat]) {
            $this->section('HIGHLIGHT', 'title', ['title' => $t, 'blurb' => $b, 'priceFrom' => $price, 'mediaId' => $this->img($img), 'link' => $link, 'category' => $cat], 10 * ($i + 1));
        }
        foreach ([['Facilities', '26', '+', 'building'], ['Courts and pitches', '9', null, 'trophy'], ['Open every week', '7', ' days', 'calendar'], ['Guests a month', '4,000', '+', 'users']] as $i => [$l, $v, $s, $ic]) {
            $this->section('STAT', 'label', ['label' => $l, 'value' => $v, 'suffix' => $s, 'icon' => $ic], 10 * ($i + 1));
        }
        $tm = [
            ['Chinedu Okafor', 'Weekend regular', 'We booked the pitch for our Saturday five-a-side and the floodlit game was unreal. Easy booking, friendly staff.', 5],
            ['Amaka Eze', 'Family of four', 'The kids lived in the pool and we finally sat down for a proper lunch of jollof and grilled fish. We are already planning the next visit.', 5],
            ['Tonye Briggs', 'Gold member', 'Membership pays for itself. Tennis mornings, spa Sundays, and the grill on Fridays.', 5],
            ['Ifeoma Nwosu', 'Corporate retreat organiser', 'Handled our team day from courts to catering without a single hiccup.', 4],
            ['Daniel Adeyemi', 'First-time visitor', 'Came for the live band night and stayed for the suya. Great atmosphere and fair prices.', 5],
        ];
        foreach ($tm as $i => [$n, $r, $q, $rt]) {
            $this->section('TESTIMONIAL', 'name', ['name' => $n, 'role' => $r, 'quote' => $q, 'rating' => $rt, 'avatarMediaId' => $this->avatarFor($n)], 10 * ($i + 1));
        }
        $faq = [
            ['How do I book a court or pitch?', 'Pick a resource and a time slot on the **Book** page and pay online. Your QR ticket arrives instantly and is scanned at the gate.'],
            ['What are your opening hours?', 'We open every day. Weekday hours are 08:00 to 22:00, and weekends run a little later. Holiday hours are listed in the footer.'],
            ['Can I buy pool tickets online?', 'Yes. Buy adult or child day tickets online and show the QR code at reception. Same-day tickets can also be bought at the gate.'],
            ['Is there a cancellation policy?', 'Bookings can be cancelled or rescheduled from your account up to the cut-off shown on the booking. Late changes may carry a fee.'],
            ['How does membership work?', 'Gold, Silver and Pool Pass plans include set visits and discounts across the resort. Join online or at reception.'],
            ['Can we bring our own food and drinks?', 'Please enjoy our restaurant and bars. Outside food and drinks are not permitted in the pool area.'],
            ['Is there parking?', 'Yes, free secure parking is available on site for guests and members.'],
            ['How do I get there?', 'We are in the Otueke area of Ogbia, Bayelsa State, close to the Federal University Otueke, so use the university as your landmark. From Yenagoa or Port Harcourt you travel by road and can ask for Federal University Otueke when you get near. Reception can send directions on WhatsApp when you book, so just ask.'],
            ['Is the resort close to the university?', 'Yes. We are near the Federal University Otueke, which makes 007 an easy place for students, staff, visiting parents and friends to meet, play and eat. Ask reception for directions and the best way to reach us from campus.'],
            ['Do you host private events?', 'We host birthdays, corporate days and team-building. Send us a message from the contact page with your date and group size.'],
        ];
        foreach ($faq as $i => [$q, $a]) {
            $this->section('FAQ', 'question', ['question' => $q, 'answer' => $a, 'topic' => null], 10 * ($i + 1));
        }
        $this->section('CTA_BAND', 'title', ['title' => 'Ready for your weekend?', 'text' => 'Book a court, grab pool tickets or join the club in a couple of minutes.', 'ctaLabel' => 'Start booking', 'ctaLink' => '/book', 'mediaId' => $this->img('hero-04')], 10);
        $this->section('PARTNER', 'name', ['name' => 'Paystack', 'logoMediaId' => null, 'link' => 'https://paystack.com'], 10);
    }

    // ---- pages ----

    private function seedPages(): void
    {
        $this->slugged('pages', 'about', [
            'title' => 'About 007 Resort & Spa', 'subtitle' => 'Sport, water, food and rest in one place', 'heroMediaId' => $this->img('hero-04'), 'showInFooter' => true, 'sortOrder' => 10,
            'seoDescription' => 'Sports arena, pool, spa, restaurant and bars near the Federal University Otueke, Bayelsa. Learn about 007 Resort & Spa.',
            'bodyMarkdown' => "## Play. Splash. Reset. Feast.\n\n007 Resort & Spa was built for the kind of weekend where everyone in the group gets what they want. Some of you want a floodlit game, some want a quiet hour by the water, and somebody always wants a second plate of jollof.\n\nOn one property you will find:\n\n- a **sports arena** with football, basketball and tennis courts\n- a **resort pool** with loungers, kids' areas and a poolside bar\n- a **spa** for massages, facials, sauna and steam\n- a **restaurant and bars** serving grills, fresh fish and cocktails\n\n### Made for groups\n\nFamilies, teams, colleagues and friends book together and pay once. Everything is scanned at the gate with a QR ticket, so there is no queue at the desk.\n\n### Find us\n\nWe are in Otueke, Ogbia, Bayelsa State, close to the Federal University Otueke. Students, staff, families, colleagues and weekend visitors from Yenagoa, Port Harcourt and beyond all end up at the same pool deck by Friday evening. See the contact page for directions and opening hours, or ask reception for directions when you book.",
        ]);
        $this->slugged('pages', 'contact', [
            'title' => 'Contact 007 Resort & Spa', 'subtitle' => 'Near the Federal University Otueke, Bayelsa State', 'showInFooter' => false, 'sortOrder' => 5,
            'seoDescription' => 'Address, phone, WhatsApp, opening hours and directions to 007 Resort & Spa, close to the Federal University Otueke, Bayelsa State.',
            'bodyMarkdown' => "## Getting here\n\n007 Resort & Spa is in Otueke, Ogbia, Bayelsa State, close to the Federal University Otueke. Use the university as your landmark.\n\n- **From the Federal University Otueke:** we are close by. Ask reception for the best route from campus.\n- **From Yenagoa:** travel by road towards Otueke and ask for the Federal University Otueke when you get near.\n- **From Port Harcourt:** travel by road into Bayelsa State towards Otueke. Allow extra time for traffic and ask reception for current directions.\n- **By taxi or bike:** tell the driver \"Federal University Otueke\", then call reception and we will guide them in.\n\nJourney times change with traffic and road conditions, so we do not quote exact distances. **Ask reception for directions** by phone or WhatsApp, and share your live location when you set off. The map on this page is approximate.\n\nFree secure parking is available on site.",
        ]);
        $this->slugged('pages', 'house-rules', [
            'title' => 'House rules', 'subtitle' => 'Keeping the resort safe and fun for everyone', 'showInFooter' => true, 'sortOrder' => 20,
            'bodyMarkdown' => "## Before you arrive\n\n1. Bring your QR ticket or booking confirmation.\n2. Children under 12 must be with an adult at the pool and courts.\n\n## On site\n\n- Swimwear only in the pool; shower before you swim.\n- No glass in the pool area.\n- Outside food and drinks are not permitted.\n- Proper sports shoes on tennis and basketball courts.\n- Respect other guests and staff. Management may ask anyone to leave for unsafe or abusive behaviour.\n\n## Safety\n\nLifeguards are on duty during opening hours. Follow staff instructions at all times.",
        ]);
        $this->slugged('pages', 'terms', [
            'title' => 'Terms of use', 'subtitle' => 'Tickets, bookings and payments', 'showInFooter' => true, 'sortOrder' => 30,
            'bodyMarkdown' => "## Tickets and bookings\n\nA ticket or booking is valid for the date and time shown. Tickets are personal to the bearer and may be scanned once per entry.\n\n## Payments\n\nOnline payments are processed by our payment partner. Prices are in Nigerian Naira (NGN).\n\n## Cancellations\n\nCancellations and rescheduling follow the policy shown on your booking at the time of purchase.\n\n## Privacy\n\nWe store the details you give us to run your booking and, only if you opt in, to send news and offers. You can unsubscribe at any time.\n\n*This is demo text for the development environment and must be replaced with your approved terms before launch.*",
        ]);
    }

    // ---- blog ----

    private function seedBlog(): void
    {
        $cats = [];
        foreach ([['news', 'News', 'What is new at the resort'], ['sports', 'Sports', 'Courts, pitches, coaching and tournaments'], ['food-drink', 'Food & Drink', 'From the grill and the bar']] as $i => [$slug, $name, $desc]) {
            $id = DB::table('cms_post_category')->where('slug', $slug)->value('id');
            if ($id === null) {
                $c = $this->content->create($this->res('post-categories'), ['slug' => $slug, 'name' => $name, 'description' => $desc, 'sortOrder' => 10 * ($i + 1)]);
                $id = Ids::toBinary($c['id']);
            }
            $cats[$slug] = Rows::id($id);
        }
        $now = CarbonImmutable::now('UTC');
        $posts = [
            ['friday-night-live-is-back', 'Friday Night Live is back', 'news', ['events', 'music'], 'events-01', 3, true, 'Live band, grill and cocktails return to the poolside deck every Friday.',
                "Every Friday from 7pm the poolside deck turns into the best seat near the Federal University Otueke.\n\n## What to expect\n\n- A live band playing highlife, afrobeats and covers\n- The grill open until late\n- Two-for-one cocktails until 8pm\n\nTickets are limited, so book ahead from the **Events** page. Groups of six or more can reserve a cabana."],
            ['new-floodlights-on-the-arena', 'New floodlights on the arena', 'sports', ['football', 'basketball'], 'football-02', 10, false, 'The football pitch and basketball court now run under new LED floodlights.',
                "We have finished upgrading the sports arena with new LED floodlights, which means brighter games and later kick-offs.\n\nEvening slots are now bookable until 22:00 on both the football pitch and the basketball court. Early evening is the busiest window, so reserve your slot a few days ahead.\n\n> Tip: a five-a-side team can split the hourly price and still pay less than a cinema ticket each."],
            ['how-to-plan-a-family-pool-day', 'How to plan the perfect family pool day', 'news', ['pool', 'family'], 'pool-02', 17, false, 'Arrive early, pick a shaded lounger and let the kids run out of energy first.',
                "A great pool day is mostly about timing.\n\n1. **Arrive before 11am** to claim shaded loungers near the kids' area.\n2. **Buy tickets online** so you can walk straight in with the QR code.\n3. **Swim first, eat second.** The restaurant is quietest between 2pm and 4pm.\n4. **Pack a spare towel** and sunscreen; the shop by the gate has both if you forget.\n\nCabanas can be booked for larger groups."],
            ['serve-up-tennis-mornings', 'Serve up: tennis mornings for beginners', 'sports', ['tennis', 'coaching'], 'tennis-02', 24, false, 'Weekday morning coaching for first-timers, with rackets and balls provided.',
                "New to tennis? Our weekday morning sessions are designed for you.\n\nA coach walks you through grip, footwork and your first rallies on the clay court. Rackets and balls are included, and sessions are small so you get real attention.\n\nBook a one-hour court slot and ask for a coach at reception, or join the Gold membership for included coaching credits."],
            ['inside-the-grill-jollof-and-suya', 'Inside the grill: jollof, suya and fire', 'food-drink', ['restaurant', 'grill'], 'dining-03', 31, true, 'Our chefs share how the restaurant gets that smoky flavour.',
                "The secret to our jollof is patience and a little smoke. Rice is finished on the same charcoal grill that cooks the suya, so it picks up flavour you cannot fake.\n\nThe grill menu changes weekly with the catch and the market, but the classics always stay: peppered chicken, flame-grilled fish and skewers with yaji spice.\n\nBooking a table is easy for groups; just add your date and headcount from the contact page."],
            ['cocktails-by-the-water', 'Cocktails by the water', 'food-drink', ['bar', 'drinks'], 'dining-05', 38, false, 'Our poolside bar menu, from zero-proof coolers to long summer drinks.',
                "The poolside bar has a short menu built for hot afternoons: citrus, ginger, hibiscus and plenty of ice.\n\n### Favourites\n\n- **Zobo spritz**, hibiscus, ginger and lime\n- **Palm sunset**, pineapple, orange and grenadine (alcohol-free)\n- **The 007**, a dry martini for the traditionalists\n\nAsk the bartender for a tasting flight."],
        ];
        foreach ($posts as [$slug, $title, $cat, $tags, $img, $daysAgo, $featured, $excerpt, $body]) {
            $this->slugged('posts', $slug, [
                'title' => $title, 'excerpt' => $excerpt, 'bodyMarkdown' => $body, 'coverMediaId' => $this->img($img), 'authorName' => 'The 007 team', 'categoryId' => $cats[$cat],
                'tags' => $tags, 'featured' => $featured,
            ], $now->subDays($daysAgo));
        }
    }

    // ---- events ----

    private function seedEvents(): void
    {
        $tz = 'Africa/Lagos';
        $local = fn (string $modify, int $h, int $m = 0) => CarbonImmutable::now($tz)->modify($modify)->setTime($h, $m);
        $iso = fn (CarbonImmutable $t) => $t->toIso8601String();
        $ev = self::EVENTS;
        foreach ($ev as [$slug, $title, $cat, $summary, $img, $when, $h, $hours, $venue, $price, $cap, $rec, $feat, $body]) {
            $start = $local($when, $h);
            $this->slugged('events', $slug, [
                'title' => $title, 'summary' => $summary, 'bodyMarkdown' => $body, 'coverMediaId' => $this->img($img), 'category' => $cat,
                'startsAt' => $iso($start), 'endsAt' => $iso($start->addHours($hours)), 'venueLabel' => $venue, 'priceText' => $price, 'capacity' => $cap,
                'recurrence' => $rec, 'featured' => $feat, 'ticketUrl' => null,
            ], CarbonImmutable::now('UTC')->subDays(40));
        }
    }

    // ---- gallery ----

    private function seedGallery(): void
    {
        $albums = self::ALBUMS;
        foreach ($albums as $i => [$slug, $title, $desc, $files]) {
            $cover = $this->img($files[0]);
            $id = $this->slugged('gallery/albums', $slug, ['title' => $title, 'description' => $desc, 'coverMediaId' => $cover, 'sortOrder' => 10 * ($i + 1)]);
            if ($id === null || DB::table('cms_gallery_item')->where('album_id', Ids::toBinary($id))->exists()) {
                continue;
            }
            $items = [];
            foreach ($files as $j => $f) {
                if (($m = $this->img($f)) !== null) {
                    $items[] = ['mediaId' => $m, 'caption' => null, 'category' => explode('-', $f)[0], 'featured' => $j === 0, 'sortOrder' => 10 * ($j + 1)];
                }
            }
            if ($items !== []) {
                $this->gallery->add($id, ['items' => $items]);
            }
        }
    }
}
