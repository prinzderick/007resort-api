<?php

namespace App\Domain\Cms\Resources;

use App\Domain\Cms\Support\Cms;
use App\Domain\Cms\Support\Markdown;
use App\Domain\Cms\Support\MediaExists;
use App\Domain\Cms\Support\MediaResolver;
use App\Domain\Cms\Support\Recurrence;
use App\Domain\Cms\Support\Rows;
use App\Domain\Cms\Support\Slugs;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EventResource extends ContentResource
{
    protected array $map = [
        'slug' => 'slug', 'title' => 'title', 'summary' => 'summary', 'bodyMarkdown' => 'body_markdown', 'coverMediaId' => 'cover_media_id', 'category' => 'category',
        'venueLabel' => 'venue_label', 'facilityId' => 'facility_id', 'priceText' => 'price_text', 'capacity' => 'capacity', 'ticketUrl' => 'ticket_url',
        'ticketProductId' => 'ticket_product_id', 'recurrence' => 'recurrence', 'featured' => 'is_featured', 'seoTitle' => 'seo_title', 'seoDescription' => 'seo_description',
        'seoOgMediaId' => 'seo_og_media_id',
    ];

    protected array $uuids = ['coverMediaId', 'facilityId', 'ticketProductId', 'seoOgMediaId'];

    protected array $bools = ['featured'];

    public function key(): string
    {
        return 'events';
    }

    public function table(): string
    {
        return 'cms_event';
    }

    public function entity(): string
    {
        return 'event';
    }

    public function requireIfMatch(): bool
    {
        return true;
    }

    public function fields(): array
    {
        $offset = fn ($a, $v, $fail) => (is_string($v) && preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $v) === 1) || $fail('The :attribute must be an ISO-8601 timestamp with a Z or +hh:mm offset.');
        $exists = fn (string $table) => fn ($a, $v, $fail) => (is_string($v) && Ids::isUuid($v) && DB::table($table)->where('id', Ids::toBinary($v))->exists()) || $fail('Unknown reference.');

        return [
            'slug' => ['string', 'max:120', 'regex:'.Slugs::REGEX], 'title' => ['string', 'max:160'], 'summary' => ['string', 'max:300'], 'bodyMarkdown' => ['string', 'max:100000'],
            'coverMediaId' => [new MediaExists], 'category' => [Rule::in(Cms::EVENT_CATEGORIES)],
            'startsAt' => ['date', $offset], 'endsAt' => ['date', $offset], 'venueLabel' => ['string', 'max:120'], 'facilityId' => [$exists('facility_unit')],
            'priceText' => ['string', 'max:80'], 'capacity' => ['integer', 'min:1', 'max:1000000'], 'ticketUrl' => ['url:http,https', 'max:500'],
            'ticketProductId' => [$exists('product')], 'recurrence' => [Rule::in(['NONE', 'WEEKLY'])], 'recurrenceUntil' => ['date_format:Y-m-d'],
            'featured' => ['boolean'], 'seoTitle' => ['string', 'max:70'], 'seoDescription' => ['string', 'max:200'], 'seoOgMediaId' => [new MediaExists],
        ];
    }

    public function required(): array
    {
        return ['title', 'category', 'startsAt', 'endsAt'];
    }

    public function beforeSave(array &$d, ?object $existing): void
    {
        $start = isset($d['startsAt']) ? CarbonImmutable::parse($d['startsAt'])->utc() : ($existing ? Rows::carbon($existing->starts_at) : null);
        $end = isset($d['endsAt']) ? CarbonImmutable::parse($d['endsAt'])->utc() : ($existing ? Rows::carbon($existing->ends_at) : null);
        $errors = [];
        if ($start && $end) {
            if ($end->lte($start)) {
                $errors['endsAt'] = ['endsAt must be after startsAt.'];
            } elseif ($end->diffInHours($start, true) > 14 * 24) {
                $errors['endsAt'] = ['An event may span at most 14 days; use recurrence for repeats.'];
            }
        }
        $rec = $d['recurrence'] ?? $existing?->recurrence ?? 'NONE';
        $until = array_key_exists('recurrenceUntil', $d) ? $d['recurrenceUntil'] : $existing?->recurrence_until;
        if ($rec === 'NONE') {
            $d['recurrence'] = 'NONE';
            $until = null;
        } elseif ($until !== null && $start && CarbonImmutable::parse($until, Cms::tz())->endOfDay()->lt($start)) {
            $errors['recurrenceUntil'] = ['recurrenceUntil must not be before the first occurrence.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $d['_starts'] = $start;
        $d['_ends'] = $end;
        $d['_until'] = $until;
        $d['_touchRecurrence'] = array_key_exists('recurrenceUntil', $d) || array_key_exists('recurrence', $d);
    }

    protected function extra(array $d, ?object $existing): array
    {
        $out = [];
        if (isset($d['startsAt'])) {
            $out['starts_at'] = Rows::db($d['_starts']);
        }
        if (isset($d['endsAt'])) {
            $out['ends_at'] = Rows::db($d['_ends']);
        }
        if ($d['_touchRecurrence'] ?? false) {
            $out['recurrence_until'] = $d['_until'];
        }
        if (array_key_exists('bodyMarkdown', $d)) {
            $out['body_html'] = Markdown::html($d['bodyMarkdown']);
        }

        return $out;
    }

    public function order(): array
    {
        return ['starts_at', 'desc'];
    }

    public function filter(Builder $q, Request $r): void
    {
        parent::filter($q, $r);
        if (in_array($c = strtoupper((string) $r->query('category')), Cms::EVENT_CATEGORIES, true)) {
            $q->where('category', $c);
        }
        $now = Rows::now();
        if ($r->query('when') === 'upcoming') {
            $q->where(fn ($w) => $w->where('ends_at', '>=', $now)->orWhere(fn ($w2) => $w2->where('recurrence', 'WEEKLY')->where(fn ($w3) => $w3->whereNull('recurrence_until')->orWhere('recurrence_until', '>=', substr($now, 0, 10)))));
        } elseif ($r->query('when') === 'past') {
            $q->where('ends_at', '<', $now)->where(fn ($w) => $w->where('recurrence', 'NONE')->orWhere('recurrence_until', '<', substr($now, 0, 10)));
        }
    }

    public function admin(object $r, MediaResolver $media): array
    {
        $tz = Cms::tz();
        $s = Rows::carbon($r->starts_at);
        $e = Rows::carbon($r->ends_at);
        $next = Recurrence::next($r, CarbonImmutable::now('UTC'), 1)[0] ?? null;

        return $this->head($r) + [
            'title' => $r->title, 'summary' => $r->summary, 'bodyMarkdown' => $r->body_markdown, 'bodyHtml' => $r->body_html,
            'coverMediaId' => Rows::id($r->cover_media_id), 'cover' => $media->admin(Rows::id($r->cover_media_id)), 'category' => $r->category,
            'startsAt' => Rows::iso($r->starts_at), 'endsAt' => Rows::iso($r->ends_at),
            'startsAtLocal' => $s->setTimezone($tz)->format('Y-m-d\TH:i:sP'), 'endsAtLocal' => $e->setTimezone($tz)->format('Y-m-d\TH:i:sP'), 'timezone' => $tz,
            'venueLabel' => $r->venue_label, 'facilityId' => Rows::id($r->facility_id), 'priceText' => $r->price_text, 'capacity' => $r->capacity === null ? null : (int) $r->capacity,
            'ticketUrl' => $r->ticket_url, 'ticketProductId' => Rows::id($r->ticket_product_id), 'recurrence' => $r->recurrence, 'recurrenceUntil' => $r->recurrence_until,
            'featured' => (bool) $r->is_featured, 'seoTitle' => $r->seo_title, 'seoDescription' => $r->seo_description,
            'seoOgMediaId' => Rows::id($r->seo_og_media_id), 'seoOgImage' => $media->admin(Rows::id($r->seo_og_media_id)),
            'nextOccurrence' => $next ? ['startsAt' => $next['start']->format('Y-m-d\TH:i:s.v\Z'), 'endsAt' => $next['end']->format('Y-m-d\TH:i:s.v\Z')] : null,
        ] + $this->tail($r);
    }

    public function mediaIds(object $row): array
    {
        return [Rows::id($row->cover_media_id), Rows::id($row->seo_og_media_id)];
    }
}
