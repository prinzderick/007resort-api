<?php

namespace Tests\Feature\Cms;

use App\Domain\Cms\Support\Recurrence;
use App\Support\Demo\DemoIds;
use Carbon\CarbonImmutable;

class EventsTest extends CmsTestCase
{
    private function ev(string $title, string $start, int $hours = 3, array $extra = []): array
    {
        $s = CarbonImmutable::parse($start);

        return $this->published('events', $extra + ['title' => $title, 'category' => 'MUSIC', 'startsAt' => $s->toIso8601String(), 'endsAt' => $s->addHours($hours)->toIso8601String()], now('UTC')->subDays(30)->toIso8601String());
    }

    public function test_recurrence_expansion_is_pure_and_keeps_local_time(): void
    {
        $e = (object) ['starts_at' => '2026-10-02 18:00:00.000000', 'ends_at' => '2026-10-02 21:00:00.000000', 'recurrence' => 'WEEKLY', 'recurrence_until' => '2026-10-30'];
        $from = CarbonImmutable::parse('2026-10-10 00:00:00', 'UTC');
        $occ = Recurrence::next($e, $from, 10);
        // Fridays 18:00 UTC = 19:00 Lagos, from 2026-10-16 (the 9th ended before `from`) until 2026-10-30 inclusive
        $this->assertSame(['2026-10-16 18:00', '2026-10-23 18:00', '2026-10-30 18:00'], array_map(fn ($o) => $o['start']->format('Y-m-d H:i'), $occ));
        $this->assertSame(['2026-10-16 21:00'], [$occ[0]['end']->format('Y-m-d H:i')]);
        // an occurrence still running counts as upcoming
        $running = Recurrence::next($e, CarbonImmutable::parse('2026-10-09 19:30:00', 'UTC'), 1);
        $this->assertSame('2026-10-09 18:00', $running[0]['start']->format('Y-m-d H:i'));
        $this->assertSame(2, count(Recurrence::next($e, $from, 2)));
        $this->assertSame([], Recurrence::next($e, CarbonImmutable::parse('2026-11-05', 'UTC'), 5), 'past the until date');
        $none = (object) ['starts_at' => '2026-10-02 18:00:00.000000', 'ends_at' => '2026-10-02 21:00:00.000000', 'recurrence' => 'NONE', 'recurrence_until' => null];
        $this->assertCount(1, Recurrence::next($none, CarbonImmutable::parse('2026-10-02 20:00:00', 'UTC'), 5));
        $this->assertSame([], Recurrence::next($none, CarbonImmutable::parse('2026-10-03', 'UTC'), 5));
        $open = (object) ['starts_at' => '2026-10-02 18:00:00.000000', 'ends_at' => '2026-10-02 21:00:00.000000', 'recurrence' => 'WEEKLY', 'recurrence_until' => null];
        $this->assertCount(12, Recurrence::next($open, CarbonImmutable::parse('2027-01-01', 'UTC'), 12));
    }

    public function test_admin_validation_of_times_recurrence_and_references(): void
    {
        $o = $this->owner();
        $base = ['title' => 'X', 'category' => 'SPORT', 'startsAt' => '2026-12-01T18:00:00Z', 'endsAt' => '2026-12-01T20:00:00Z'];
        $o->post('/admin/cms/events', ['endsAt' => '2026-12-01T20:00:00Z'] + $base)->assertCreated();
        $o->post('/admin/cms/events', ['endsAt' => '2026-12-01T17:00:00Z'] + $base)->assertStatus(422)->assertJsonValidationErrors(['endsAt']);
        $o->post('/admin/cms/events', ['startsAt' => '2026-12-01 18:00:00'] + $base)->assertStatus(422)->assertJsonValidationErrors(['startsAt']);
        $o->post('/admin/cms/events', ['endsAt' => '2026-12-20T20:00:00Z'] + $base)->assertStatus(422)->assertJsonValidationErrors(['endsAt']);
        $o->post('/admin/cms/events', ['category' => 'CONCERT'] + $base)->assertStatus(422)->assertJsonValidationErrors(['category']);
        $o->post('/admin/cms/events', ['recurrence' => 'WEEKLY', 'recurrenceUntil' => '2026-11-01'] + $base)->assertStatus(422)->assertJsonValidationErrors(['recurrenceUntil']);
        $o->post('/admin/cms/events', ['recurrence' => 'DAILY'] + $base)->assertStatus(422);
        $o->post('/admin/cms/events', ['facilityId' => '0192a1b2-0000-7000-8000-000000000000'] + $base)->assertStatus(422)->assertJsonValidationErrors(['facilityId']);
        $o->post('/admin/cms/events', ['capacity' => 0] + $base)->assertStatus(422);
        $o->post('/admin/cms/events', ['ticketUrl' => 'javascript:alert(1)'] + $base)->assertStatus(422)->assertJsonValidationErrors(['ticketUrl']);

        $fac = DemoIds::facility('SPORTS_ARENA');
        $e = $o->post('/admin/cms/events', ['facilityId' => $fac, 'ticketUrl' => 'https://tickets.example.com/x', 'capacity' => 50, 'venueLabel' => 'Arena', 'priceText' => 'Free'] + $base)->assertCreated()->json();
        $this->assertSame($fac, $e['facilityId']);
        $this->assertSame('2026-12-01T19:00:00+01:00', $e['startsAtLocal']);
        $this->assertSame('Africa/Lagos', $e['timezone']);
        // If-Match required; recurrence NONE clears the until date; times can move together
        $g = $o->get("/admin/cms/events/{$e['id']}");
        $o->patch("/admin/cms/events/{$e['id']}", ['title' => 'Y'])->assertStatus(428);
        $u = $o->patch("/admin/cms/events/{$e['id']}", ['recurrence' => 'WEEKLY', 'recurrenceUntil' => '2027-01-31', 'title' => 'Y'], $this->etag($g))->assertOk();
        $this->assertSame('2027-01-31', $u->json('recurrenceUntil'));
        $n = $o->patch("/admin/cms/events/{$e['id']}", ['recurrence' => 'NONE'], ['If-Match' => $u->headers->get('ETag')])->assertOk();
        $this->assertNull($n->json('recurrenceUntil'));
        $o->patch("/admin/cms/events/{$e['id']}", ['endsAt' => '2026-11-30T00:00:00Z'], ['If-Match' => $n->headers->get('ETag')])->assertStatus(422);
    }

    public function test_public_listing_upcoming_expansion_filters_and_cursor(): void
    {
        $lagos = 'Africa/Lagos';
        $friday = CarbonImmutable::now($lagos)->modify('last friday')->setTime(19, 0);
        $weekly = $this->ev('Friday Live', $friday->toIso8601String(), 4, ['recurrence' => 'WEEKLY', 'category' => 'MUSIC', 'featured' => true, 'summary' => 'Band night', 'priceText' => 'NGN 5,000']);
        $yoga = $this->ev('Sunday Yoga', CarbonImmutable::now($lagos)->modify('last sunday')->setTime(17, 0)->toIso8601String(), 1, ['recurrence' => 'WEEKLY', 'category' => 'WELLNESS', 'recurrenceUntil' => CarbonImmutable::now($lagos)->addDays(10)->format('Y-m-d')]);
        $soon = $this->ev('Match Night', CarbonImmutable::now($lagos)->addDays(5)->setTime(20, 0)->toIso8601String(), 3, ['category' => 'SPORT']);
        $far = $this->ev('Big Party', CarbonImmutable::now($lagos)->addDays(40)->setTime(21, 0)->toIso8601String(), 5, ['category' => 'PARTY', 'ticketUrl' => 'https://t.example.com/party']);
        $past = $this->ev('Old Festival', CarbonImmutable::now($lagos)->subDays(20)->setTime(12, 0)->toIso8601String(), 5, ['category' => 'FOOD']);
        $endedSeries = $this->ev('Ended Series', CarbonImmutable::now($lagos)->subDays(40)->setTime(12, 0)->toIso8601String(), 2, ['recurrence' => 'WEEKLY', 'recurrenceUntil' => CarbonImmutable::now($lagos)->subDays(20)->format('Y-m-d')]);
        $this->o('/admin/cms/events', ['title' => 'Hidden draft', 'category' => 'OTHER', 'startsAt' => now('UTC')->addDay()->toIso8601String(), 'endsAt' => now('UTC')->addDay()->addHour()->toIso8601String()]);

        $up = $this->pub('/events?upcoming=true')->assertOk();
        $items = $up->json('items');
        $slugs = array_column($items, 'slug');
        $this->assertNotContains('old-festival', $slugs);
        $this->assertNotContains('ended-series', $slugs);
        $this->assertNotContains('hidden-draft', $slugs);
        $this->assertSame(4, count(array_filter($slugs, fn ($s) => $s === 'friday-live')), 'default 4 occurrences per weekly series');
        $starts = array_column($items, 'startsAt');
        $sorted = $starts;
        sort($sorted);
        $this->assertSame($sorted, $starts, 'soonest first');
        $this->assertSame(count($items), count(array_unique(array_column($items, 'occurrenceKey'))));
        foreach ($items as $i) {
            $this->assertGreaterThanOrEqual(now('UTC')->subMinute()->format('c'), CarbonImmutable::parse($i['endsAt'])->format('c'), 'no ended occurrence in upcoming');
        }
        $fl = array_values(array_filter($items, fn ($i) => $i['slug'] === 'friday-live'))[0];
        $this->assertTrue($fl['isRecurring']);
        $this->assertSame('WEEKLY', $fl['recurrence']['type']);
        $this->assertSame('19:00:00+01:00', substr($fl['startsAtLocal'], 11), 'weekly occurrences keep their Lagos wall-clock time');
        $this->assertSame('Africa/Lagos', $fl['timezone']);
        $this->assertSame('MUSIC', $fl['category']);
        // yoga stops at its until date (10 days ahead -> at most 2 Sundays)
        $this->assertLessThanOrEqual(2, count(array_filter($slugs, fn ($s) => $s === 'sunday-yoga')));

        $this->assertSame(['friday-live'], array_values(array_unique(array_column($this->pub('/events?upcoming=true&category=MUSIC')->json('items'), 'slug'))));
        $this->assertSame(['match-night'], array_column($this->pub('/events?upcoming=true&category=SPORT')->json('items'), 'slug'));
        $this->assertSame(['friday-live'], array_values(array_unique(array_column($this->pub('/events?upcoming=true&featured=true')->json('items'), 'slug'))));
        $this->assertCount(2, array_filter(array_column($this->pub('/events?upcoming=true&occurrences=2&category=MUSIC')->json('items'), 'slug')));
        $this->assertLessThanOrEqual(12, count($this->pub('/events?upcoming=true&occurrences=99&category=MUSIC&limit=50')->json('items')));

        // cursor: pages of 3 walk the same list once
        $all = array_column($items, 'occurrenceKey');
        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $r = $this->pub('/events?upcoming=true&limit=3'.($cursor ? '&cursor='.$cursor : ''))->assertOk();
            $seen = array_merge($seen, array_column($r->json('items'), 'occurrenceKey'));
            $cursor = $r->json('nextCursor');
            $pages++;
        } while ($cursor && $pages < 20);
        $this->assertSame($all, $seen);
        $this->assertGreaterThan(1, $pages);
        $this->pub('/events?upcoming=true&cursor=bad')->assertStatus(400);

        // past = ended only; default = every published event newest start first, not expanded
        $this->assertEqualsCanonicalizing(['old-festival', 'ended-series'], array_column($this->pub('/events?upcoming=false')->json('items'), 'slug'));
        $all2 = array_column($this->pub('/events')->json('items'), 'slug');
        $this->assertSame(6, count($all2));
        $this->assertSame('big-party', $all2[0]);

        // ticket deep link + detail with nextOccurrences
        $party = $this->pub('/events?upcoming=true&category=PARTY')->json('items.0');
        $this->assertSame('https://t.example.com/party', $party['ticket']['url']);
        $this->assertNull($this->pub('/events?upcoming=true&category=SPORT')->json('items.0.ticket'));
        $d = $this->pub('/events/friday-live')->assertOk();
        $this->assertCount(8, $d->json('nextOccurrences'));
        $this->assertArrayHasKey('bodyHtml', $d->json());
        $this->assertSame($d->json('nextOccurrences.0.startsAt'), $d->json('startsAt'));
        $this->assertCount(0, $this->pub('/events/old-festival')->json('nextOccurrences'));
        $this->pub('/events/hidden-draft')->assertStatus(404);
        // upcoming events are in the sitemap; the endpoint is cacheable
        $this->assertContains('friday-live', array_column($this->pub('/sitemap')->json('items'), 'slug'));
        $etag = $this->pub('/events?upcoming=true')->headers->get('ETag');
        $this->pub('/events?upcoming=true', ['If-None-Match' => $etag])->assertStatus(304);
        // admin list filter
        $adm = $this->owner()->get('/admin/cms/events?when=upcoming')->assertOk();
        $this->assertNotContains('old-festival', array_column($adm->json('items'), 'slug'));
        $this->assertNotNull($this->owner()->get("/admin/cms/events/{$weekly['id']}")->json('nextOccurrence'));
        $this->assertNull($this->owner()->get("/admin/cms/events/{$past['id']}")->json('nextOccurrence'));
    }

    private function o(string $path, array $body): void
    {
        $this->owner()->post($path, $body)->assertCreated();
    }
}
