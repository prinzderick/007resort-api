# Website CMS API (`app/Domain/Cms`)

Everything the public website shows (site settings, home page blocks, pages, blog, events, gallery) plus email subscribe and the contact form, managed from the admin portal.
Two consumers build on this contract: `007resort-booking-web` (public reads, section 3) and `007resort-admin-web` (management, section 4). OpenAPI: `docs/openapi/v1.yaml` (tag `Website CMS`, operations marked `x-additive: true`).

Conventions are the platform's: `/api/v1`, camelCase JSON, ids UUIDv7 strings, timestamps ISO-8601 UTC (`2026-10-03T17:00:00.000Z`), RFC 7807 `application/problem+json` errors with a stable `code`, cursor pagination `{ "items": [], "nextCursor": null|string }`.

## 1. Auth model

| Surface | Credential | Notes |
| --- | --- | --- |
| `/api/v1/public/cms/*` | website service token `Authorization: Bearer r7s_...` (scope `public.read`, same token the site already uses, see `docs/CUSTOMER_PUBLIC_API.md`); a customer or staff token is also accepted | Service tokens may also call the public **POST** endpoints of this module (subscribe/confirm/unsubscribe/contact); everywhere else they stay GET-only. No token: `401 unauthenticated`. |
| `/api/v1/admin/cms/*` | staff token `r7a_...` (+ `X-Device-Token` optional) | Permission based (section 5). |

Public reads return only `PUBLISHED` content whose `publishedAt <= now` (scheduled publishing works by setting a future `publishedAt`). A staff token holding `cms.view` may add `?preview=true` to public list/detail reads to see DRAFT content too (used by the admin "preview" button); other actors ignore it.

The DEV service token `r7s_dev_booking_web` is created by `r007:demo-seed`.

### Caching (public reads)

Every GET has `ETag` (strong, body hash) and `Cache-Control: public, max-age=60, stale-while-revalidate=300`; `If-None-Match` gives `304`. `site`, `home`, `sitemap` use `max-age=60`; lists too. The BFF should cache per URL for 60 s.

### Rate limits (public)

Reads: 240/min per token (`customer-api` limiter). `POST subscribers`: 5/hour per email+client and 20/hour per client. `POST contact`: 5/hour per email+client and 20/hour per client. Exceeded: `429 rate_limited` + `Retry-After`.
"Client" is `X-Client-IP` (honoured **only** for service tokens; the BFF must forward the visitor's IP) else the socket IP. Unsubscribe/confirm: 30/min per client.

## 2. Shared shapes

### Media object

```json
{
  "id": "0192...", "url": "https://host/storage/cms/2026/10/0192....jpg",
  "width": 1600, "height": 1067, "mimeType": "image/jpeg", "alt": "Guests by the pool",
  "credit": "Photo: Jane Doe / Unsplash", "dominantColor": "#3b7a9c",
  "variants": [
    {"width": 480,  "format": "webp", "url": "https://host/storage/cms/2026/10/0192...-480.webp"},
    {"width": 960,  "format": "webp", "url": ".../0192...-960.webp"},
    {"width": 1600, "format": "webp", "url": ".../0192...-1600.webp"}
  ]
}
```
`url` is the (EXIF-stripped) original, always absolute. Variants only exist for widths smaller than or equal to the original; use them for `srcset`. Admin adds `sourceUrl`, `tags[]`, `sizeBytes`, `originalName`, `usageCount`, `rowVersion`, `createdAt`, `updatedAt`. `alt` may be empty string (decorative).

### Media references

Wherever a stored object has a field named `<x>MediaId` (`logoMediaId`, `ogImageMediaId`, `mediaId`, `avatarMediaId`, `coverMediaId`, `heroMediaId`), the **public** response replaces it with `<x>` = Media object or `null` (`mediaId` becomes `media`, `logoMediaId` becomes `logo`). Admin responses keep the id fields and add the expanded object for entities with direct columns (`cover`, `hero`); settings and home-section lists carry a top-level `media` map `{ "<mediaId>": Media }`.

### Markdown

`body`/`answer` fields are stored as Markdown. Raw HTML is stripped, unsafe links (`javascript:`, `data:`) are dropped. Public responses carry `bodyHtml` (safe, render as-is) and `bodyMarkdown`.

### Status

`DRAFT | PUBLISHED | ARCHIVED` for pages, posts, events, gallery albums. `publishedAt` is set by `publish`.

### Slugs

`^[a-z0-9]+(?:-[a-z0-9]+)*$`, max 120, unique per resource type. Omitted on create: generated from the title (`-2`, `-3`... on collision). Explicit duplicate: `409 slug_taken`.

## 3. Public endpoints (`/api/v1/public/cms`)

### `GET /site`
All site-wide settings in one call.
```json
{
  "brand":   {"name": "007 Resort & Spa", "tagline": "...", "logo": Media|null},
  "contact": {"phone": "+234...", "whatsapp": "+234...", "email": "...", "address": "...", "mapEmbedUrl": "https://..."|null, "lat": 4.9|null, "lng": 6.2|null},
  "hours":   {"weekly": [{"day": "MON", "open": "08:00", "close": "22:00", "closed": false}, ... 7 entries MON..SUN],
              "notes": "...", "holidays": [{"date": "2026-12-25", "label": "Christmas Day", "open": "10:00", "close": "18:00", "closed": false}]},
  "social":  {"instagram": "https://...", "facebook": null, "x": null, "tiktok": null, "youtube": null},
  "seo":     {"titleTemplate": "%s | 007 Resort & Spa", "defaultTitle": "...", "defaultDescription": "...", "ogImage": Media|null},
  "announcement": {"enabled": true, "text": "...", "link": "/events"|null, "tone": "INFO|PROMO|WARNING"},
  "booking": {"ticketsCtaLabel": "Book tickets", "bookingCtaLabel": "Book a court", "membershipCtaLabel": "Join the club", "eventsCtaLabel": "Get tickets"},
  "footer":  {"text": "...", "copyright": "..."},
  "updatedAt": "2026-10-03T09:00:00.000Z"
}
```
Times are wall-clock in the property's timezone (`Africa/Lagos`); hours are display data, not booking authority.

### `GET /home`
```json
{
  "sections": [ {"id": "...", "type": "HERO_SLIDE", "sortOrder": 10, ...type fields... }, ... ],
  "byType": {"HERO_SLIDE": [...], "HIGHLIGHT": [...], "STAT": [...], "TESTIMONIAL": [...], "FAQ": [...], "PARTNER": [...], "CTA_BAND": [...]},
  "updatedAt": "..."
}
```
Only enabled sections, ordered by `sortOrder` then creation; `byType` always contains every type key (possibly `[]`). Type fields are flattened into the section object (see the table in 4.3). FAQ items carry `answerHtml` next to `answer`.

### `GET /pages` and `GET /pages/{slug}`
List: `{items: [{slug, title, showInFooter, updatedAt}], nextCursor: null}` (published only).
Detail:
```json
{"id": "...", "slug": "about", "title": "About us", "subtitle": null, "hero": Media|null, "bodyHtml": "<p>...", "bodyMarkdown": "...",
 "seo": {"title": null, "description": "...", "ogImage": Media|null}, "showInFooter": true, "publishedAt": "...", "updatedAt": "..."}
```
Unknown / unpublished slug: `404 not_found`.

### `GET /posts` (blog)
Query: `category` (category slug), `tag`, `q` (matches title/excerpt/body), `featured=true`, `limit` (default 12, max 50), `cursor`. Order `publishedAt` desc.
Item:
```json
{"id": "...", "slug": "...", "title": "...", "excerpt": "...", "cover": Media|null, "authorName": "Ada", "category": {"slug": "news", "name": "News"}|null,
 "tags": ["pool", "family"], "readingTimeMinutes": 4, "featured": false, "publishedAt": "..."}
```
### `GET /posts/{slug}`
Item fields plus `bodyHtml`, `bodyMarkdown`, `seo {title, description, ogImage}`, `updatedAt`, `related: [Item x up to 3]` (same category / shared tags, newest first).
### `GET /post-categories`
`{items: [{id, slug, name, description, postCount}], nextCursor: null}` (only categories with at least one published post are returned unless `all=true`).

### `GET /events`
Query: `upcoming` (`true`: only occurrences that have not ended, **recurrence expanded**, soonest first; `false`: only ended events, newest first; omitted: every published event, newest start first, not expanded), `category` (`SPORT|MUSIC|PARTY|WELLNESS|FOOD|OTHER`), `featured=true`, `occurrences` (per weekly series, default 4, max 12; `upcoming=true` only), `limit` (default 12, max 50), `cursor`.
Item:
```json
{"id": "...", "slug": "friday-live-band", "title": "...", "summary": "...", "cover": Media|null, "category": "MUSIC",
 "startsAt": "2026-10-09T17:00:00.000Z", "endsAt": "2026-10-09T21:00:00.000Z",
 "startsAtLocal": "2026-10-09T18:00:00+01:00", "endsAtLocal": "2026-10-09T22:00:00+01:00", "timezone": "Africa/Lagos",
 "venue": {"label": "Poolside Deck", "facilityId": "..."|null},
 "priceText": "From NGN 5,000", "capacity": 200|null,
 "ticket": {"url": "https://..."|null, "productId": "..."|null, "facilityId": "..."|null}|null,
 "recurrence": {"type": "NONE|WEEKLY", "until": "2026-12-31"|null}, "isRecurring": true, "occurrenceKey": "friday-live-band@2026-10-09T17:00:00Z",
 "featured": true, "publishedAt": "..."}
```
`startsAt/endsAt` are those of the occurrence; a weekly series repeats on the same local weekday and time (Lagos has no DST). With `upcoming=true` the same event id can appear several times (distinct `occurrenceKey`). The cursor for `upcoming=true` is opaque.
`ticket` deep-links: `url` external link, or `productId` (a ticket product from `/catalog/products`) / `facilityId` so the site can open its booking flow.

### `GET /events/{slug}`
Item plus `bodyHtml`, `bodyMarkdown`, `seo`, `updatedAt`, `nextOccurrences: [{startsAt, endsAt, startsAtLocal, endsAtLocal}]` (next 8, or the single occurrence).

### `GET /gallery/albums`
`{items: [{id, slug, title, description, cover: Media|null, itemCount, sortOrder}], nextCursor: null}` ordered by `sortOrder`.
### `GET /gallery/albums/{slug}`
Album fields plus `items: [{id, media: Media, caption, alt, tags: [], category, featured, sortOrder}]` (max 500, ordered). `alt` = item alt text, falling back to the media alt.

### `GET /sitemap`
`{"generatedAt": "...", "items": [{"type": "page|post|event|album", "slug": "about", "lastModified": "2026-10-03T09:00:00.000Z"}]}` for every published page/post/event/album. The site maps `type+slug` to its own URL scheme.

### `POST /subscribers`
Body: `{"email": "a@b.co", "name": "Ada"?, "source": "footer|blog|event|popup|checkout" (default footer), "consent": true, "consentText": "I agree ..."?, "website": ""}`. `website` is a honeypot (must stay empty).
`consent` must be `true` (else `422 validation_failed`). Response is **always** `200 {"status": "CHECK_EMAIL"}` for a syntactically valid request, whether the address is new, pending, already confirmed, previously unsubscribed, disposable, or filled the honeypot: existence is never leaked. New/unsubscribed addresses become `PENDING` and receive a confirmation email (queued); a `PENDING` address gets the mail again at most every 2 minutes; `CONFIRMED` is untouched.
Errors: `422 validation_failed`, `429 rate_limited`.

### `GET /subscribers/confirm/{token}` (preview, no side effect: mail scanners prefetch links)
`200 {"valid": true, "status": "PENDING|CONFIRMED"}`; unknown/tampered `404 invalid_token`; expired `410 token_expired`.
### `POST /subscribers/confirm/{token}`
Confirms. `200 {"status": "CONFIRMED"}`; replaying a used token is `200` too. Errors as above.
### `POST /subscribers/unsubscribe/{token}`
One-click unsubscribe with the signed token from the email footer (`List-Unsubscribe` header too). `200 {"status": "UNSUBSCRIBED"}` (idempotent); tampered token `404 invalid_token`.
The site route the emails link to is `CMS_WEB_URL` + `/newsletter/confirm?token=...` and `/newsletter/unsubscribe?token=...` (env `CMS_WEB_URL`, falls back to `CUSTOMER_WEB_URL`); the site page must call the POST endpoints above.

### `POST /contact`
Body `{"name": "...", "email": "...", "phone": "..."?, "topic": "GENERAL|BOOKING|EVENTS|MEMBERSHIP|FEEDBACK|PRESS|OTHER" (default GENERAL), "message": "10..5000 chars", "website": ""}`. `201 {"status": "RECEIVED"}`. A filled honeypot also returns `201` but the message is stored as `SPAM`. Errors `422`, `429`.

## 4. Admin endpoints (`/api/v1/admin/cms`)

All under `Authorization: Bearer <staff token>`. Creating `POST`s (and media upload) require `Idempotency-Key`. Every write is audited (`cms.<entity>.<action>`, old/new values, hash-chained, same transaction). Edits of settings, pages, posts and events require `If-Match: "<rowVersion>"` (`428` if missing, `412 concurrency_conflict` if stale); every read returns `ETag: "<rowVersion>"` and a `rowVersion` field. Other entities accept `If-Match` optionally.
List endpoints: `limit` (default 50, max 200), `cursor`, `q`, `status`. List order: `updatedAt` desc unless noted.

### 4.1 Meta and utilities
* `GET /meta` [`cms.view`]: `{"statuses": [...], "eventCategories": [...], "homeSectionTypes": {"HERO_SLIDE": {"fields": [{"name": "headline", "kind": "string", "required": true, "max": 140, "options": null}, ...]}, ...}, "settingGroups": ["brand", ...], "contactTopics": [...], "subscriberSources": [...], "timezone": "Africa/Lagos", "media": {"maxBytes": 8388608, "mimeTypes": ["image/jpeg", "image/png", "image/webp", "image/avif"]}}`. Use it to render forms generically.
* `GET /summary` [`cms.view`]: `{"pages": {"draft": 0, "published": 3}, "posts": {...}, "events": {...}, "albums": {...}, "subscribers": {"pending": 0, "confirmed": 0, "unsubscribed": 0}, "messages": {"new": 0}, "media": {"count": 0}}`.
* `POST /render-preview` [`cms.view`] `{"markdown": "..."}` gives `{"html": "..."}` (identical to public rendering).

### 4.2 Settings (grouped key/value JSON, one row per group)
Groups: `brand`, `contact`, `hours`, `social`, `seo`, `announcement`, `booking`, `footer` (shapes = section 3 `site`, but media as ids: `brand.logoMediaId`, `seo.ogImageMediaId`).
* `GET /settings` [`cms.view`]: `{"groups": {"brand": {"value": {...}, "rowVersion": 3, "updatedAt": "..."}, ...}, "media": {"<id>": Media}}`.
* `GET /settings/{group}` [`cms.view`]: `{"group", "value", "rowVersion", "updatedAt"}` + `ETag`.
* `PUT /settings/{group}` [`cms.manage`] `If-Match` required, body `{"value": {...}}` replaces the whole group (validated, unknown keys rejected). Live immediately. Returns the same shape with the new `rowVersion`. `404 not_found` for an unknown group.

### 4.3 Home sections
Fields (all optional unless `*`; strings are trimmed; `link` fields accept `/relative` paths or `http(s)://` URLs):

| type | payload fields |
| --- | --- |
| `HERO_SLIDE` | `headline`* (140), `subheadline` (300), `mediaId`*, `ctaLabel` (40), `ctaLink`, `alignment` `LEFT\|CENTER\|RIGHT` (default LEFT) |
| `HIGHLIGHT` | `title`* (80), `blurb`* (400), `priceFrom` (text, 60), `mediaId`, `link`, `category`* `play\|splash\|reset\|feast` |
| `STAT` | `label`* (60), `value`* (20, text such as `26+`), `suffix` (20), `icon` (40) |
| `TESTIMONIAL` | `name`* (80), `role` (80), `quote`* (600), `rating` 1..5, `avatarMediaId` |
| `FAQ` | `question`* (200), `answer`* (Markdown, 2000), `topic` (60) |
| `PARTNER` | `name`* (80), `logoMediaId`, `link` |
| `CTA_BAND` | `title`* (120), `text` (300), `ctaLabel`* (40), `ctaLink`*, `mediaId` |

Section object (admin): `{"id", "type", "sortOrder", "enabled", "payload": {...}, "rowVersion", "createdAt", "updatedAt"}`.
* `GET /home-sections?type=&enabled=` [`cms.view`] `{items, nextCursor, media}` ordered by `sortOrder`.
* `GET /home-sections/{id}` [`cms.view`].
* `POST /home-sections` [`cms.manage`] `{"type": "...", "payload": {...}, "sortOrder"?: int}` creates **disabled** (`201`).
* `PATCH /home-sections/{id}` [`cms.manage`] `{"payload"?: {...} (full replace), "sortOrder"?: int}` (`type` immutable).
* `POST /home-sections/{id}/enable` and `/disable` [`cms.publish`].
* `DELETE /home-sections/{id}` [`cms.manage`] `204`.
* `POST /home-sections/reorder` [`cms.manage`] `{"items": [{"id": "...", "sortOrder": 10}, ...]}` (max 200, all ids must exist) `200 {"updated": n}`.

### 4.4 Pages
Fields: `slug`, `title`* (160), `subtitle` (200), `heroMediaId`, `bodyMarkdown`* (200000), `seoTitle` (70), `seoDescription` (200), `seoOgMediaId`, `showInFooter` (bool, default false), `sortOrder`.
Resource (admin): those fields + `hero: Media|null`, `seoOgImage: Media|null`, `bodyHtml`, `status`, `publishedAt`, `rowVersion`, `createdAt`, `updatedAt`.
* `GET /pages`, `GET /pages/{id}` [`cms.view`]; `POST /pages` [`cms.manage`] (201, DRAFT); `PATCH /pages/{id}` [`cms.manage`] (`If-Match`); `DELETE /pages/{id}` [`cms.manage`] (`409 must_archive_first` if PUBLISHED).
* `POST /pages/{id}/publish` `{"publishedAt"?: iso}`, `/unpublish` (back to DRAFT), `/archive` [`cms.publish`], all return the resource. Publishing twice is a no-op `200`.

### 4.5 Blog
Categories: `{id, slug, name, description, sortOrder, postCount, rowVersion}`. `GET/POST /post-categories`, `GET/PATCH/DELETE /post-categories/{id}` (`409 category_in_use` if posts reference it), `POST /post-categories/reorder`. Permissions as pages (no publish state).
Posts fields: `slug`, `title`* (160), `excerpt` (400; auto-derived from body when omitted), `bodyMarkdown`*, `coverMediaId`, `authorName` (80), `categoryId`, `tags` (array of up to 12 strings, lowercased, max 32 chars), `featured` (bool), `seoTitle`, `seoDescription`, `seoOgMediaId`. Computed: `readingTimeMinutes` (words / 220, min 1), `bodyHtml`. Resource adds `cover`, `category {id, slug, name}`, `status`, `publishedAt`, `rowVersion`.
Same verbs as pages under `/posts` (list filters `status`, `categoryId`, `tag`, `q`, `featured`).

### 4.6 Events
Fields: `slug`, `title`* (160), `summary` (300), `bodyMarkdown`, `coverMediaId`, `category`* (enum), `startsAt`* and `endsAt`* (ISO-8601; **UTC or with offset**, stored UTC; `endsAt > startsAt`, span at most 14 days), `venueLabel` (120), `facilityId` (must exist), `priceText` (80), `capacity` (int >= 1), `ticketUrl` (http/https), `ticketProductId` (must exist in catalog), `recurrence` `NONE|WEEKLY` (default NONE), `recurrenceUntil` (date `YYYY-MM-DD`, required to be >= start date when WEEKLY; may be null = open ended, expansion is bounded by `occurrences`), `featured`, `seoTitle`, `seoDescription`, `seoOgMediaId`.
Resource adds `cover`, `status`, `publishedAt`, `rowVersion`, `nextOccurrence` (or null when over). Same verbs as pages under `/events`; list filters `status`, `category`, `q`, `when=upcoming|past`.

### 4.7 Gallery
Album fields: `slug`, `title`* (120), `description` (1000), `coverMediaId`, `sortOrder`. Resource adds `cover`, `itemCount`, `status`, `publishedAt`, `rowVersion`.
`GET/POST /gallery/albums`, `GET/PATCH/DELETE /gallery/albums/{id}`, `POST .../{id}/publish|unpublish|archive` [`cms.publish`], `POST /gallery/albums/reorder`.
Items: `{id, albumId, mediaId, media, caption (300), altText (200), category (60), tags [], featured, sortOrder, rowVersion}`.
* `GET /gallery/albums/{id}/items` [`cms.view`].
* `POST /gallery/albums/{id}/items` [`cms.manage`] one item or `{"items": [...]}` (max 50); the same media may appear in several albums but only once per album (`409 duplicate_item`).
* `PATCH /gallery/items/{id}`, `DELETE /gallery/items/{id}` [`cms.manage`].
* `POST /gallery/albums/{id}/items/reorder` [`cms.manage`] `{"items": [{"id", "sortOrder"}]}`.

### 4.7a Slug/ETag summary
`PATCH` responses return the full resource with the new `ETag`. `POST` create returns `201` + `Location` + `ETag`.

### 4.8 Media
* `POST /media` [`cms.media.manage`] `multipart/form-data`: `file`* (jpeg/png/webp/avif; max 8 MB; real type detected from content; SVG and everything else refused), `alt`, `credit`, `sourceUrl`, `tags[]`. Strips EXIF/GPS, auto-rotates from EXIF orientation, stores under `storage/app/public/cms/YYYY/MM/`, creates 480/960/1600 px WebP variants (only widths below the original) and a dominant colour. `201` Media (admin shape). Errors: `413 media_too_large`, `415 media_type_unsupported`, `422 validation_failed` (missing file / not an image / decoding fails / dimensions over 8000 px).
* `GET /media?q=&tag=&cursor=` [`cms.view`] newest first. `GET /media/{id}` [`cms.view`].
* `PATCH /media/{id}` [`cms.media.manage`] `{alt?, credit?, sourceUrl?, tags?}` (replace alt/credit/tags; the file itself is immutable).
* `GET /media/{id}/usage` [`cms.view`] `{"usageCount": 2, "usage": [{"type": "post", "id": "...", "label": "Title", "field": "coverMediaId"}]}`.
* `DELETE /media/{id}` [`cms.media.manage`] `204`; `409 media_in_use` with the `usage` list when referenced (settings, home sections, pages, posts, events, albums, gallery items).

### 4.9 Subscribers
Resource: `{id, email, name, source, status: PENDING|CONFIRMED|UNSUBSCRIBED, consentText, consentedAt, confirmedAt, unsubscribedAt, createdAt}` (the IP hash is never returned).
* `GET /subscribers?status=&source=&q=&cursor=` [`cms.subscribers.view`] `{items, nextCursor, counts: {pending, confirmed, unsubscribed}}`.
* `GET /subscribers/export?status=CONFIRMED` [`cms.subscribers.export`] `text/csv; charset=utf-8` (`email,name,source,status,consentedAt,confirmedAt,unsubscribedAt`; formula-injection safe), audited (`cms.subscriber.export`, row count).
* `POST /subscribers/{id}/unsubscribe` [`cms.manage`] marks UNSUBSCRIBED (`200` resource).
* `DELETE /subscribers/{id}` [`cms.manage`] permanent erase (GDPR): hard delete; audit keeps only a hash of the address.

### 4.10 Contact messages
Resource: `{id, name, email, phone, topic, message, status: NEW|READ|REPLIED|SPAM, internalNote, handledBy, handledAt, createdAt}`.
* `GET /messages?status=&topic=&q=&cursor=` [`cms.messages.manage`] newest first, with `counts` per status. `GET /messages/{id}` (does not change status).
* `PATCH /messages/{id}` [`cms.messages.manage`] `{status?, internalNote?}`. `DELETE /messages/{id}` [`cms.messages.manage`] erase.

## 5. Permissions

| Code | Grants | Seeded to |
| --- | --- | --- |
| `cms.view` | read all admin content, preview, meta, summary | OWNER, MANAGER, IT_ADMIN, MARKETING |
| `cms.manage` | create/edit/delete content, settings, home sections; unsubscribe/erase subscribers | OWNER, MANAGER, IT_ADMIN, MARKETING |
| `cms.publish` | publish/unpublish/archive, enable/disable home sections | OWNER, MANAGER, MARKETING |
| `cms.media.manage` | upload/edit/delete media | OWNER, MANAGER, IT_ADMIN, MARKETING |
| `cms.subscribers.view` | list subscribers | OWNER, MANAGER, MARKETING |
| `cms.subscribers.export` | CSV export | OWNER, MANAGER, MARKETING |
| `cms.messages.manage` | contact inbox | OWNER, MANAGER, MARKETING |

`MARKETING` is a new data-driven role (`role.code = MARKETING`, "Marketing / Website editor"). Roles are data: assign the permissions to any custom role from the admin roles matrix. A customer/service token on an admin route: `403 permission_denied`.

## 6. Error codes

`401 unauthenticated`, `403 permission_denied` (+`permission`), `404 not_found`, `409 slug_taken`, `409 must_archive_first`, `409 media_in_use` (+`usage`), `409 category_in_use`, `409 duplicate_item`, `412`/`428 concurrency_conflict`, `413 media_too_large`, `415 media_type_unsupported`, `410 token_expired`, `404 invalid_token`, `422 validation_failed` (+`errors` field => messages), `429 rate_limited`, `400 idempotency_key_missing`.

## 7. Sync (design note, appliers are follow-up)

Content is authoritative on the node where an editor edits it (normally Cloud, where the website reads; editing on the Local node is allowed). Two event types will carry it to the other node once appliers exist; the module already has the hook (`config('cms.sync.emit')`, default `false`, env `CMS_SYNC_EMIT`) and writes them to the outbox in the change's transaction when enabled:

* `CmsContentPublished` (entity `cms_page|cms_post|cms_event|cms_gallery_album|cms_home_section|cms_setting`, `entity_version` = `row_version`): payload `{"entity": "post", "id": "...", "action": "publish|update|unpublish|archive|delete", "snapshot": {...admin resource...}}`. Receiver: version-checked upsert (same rule as `ConfigurationUpdated`), last writer wins by `row_version`.
* `CmsMediaUploaded` (entity `cms_media`): payload `{"id": "...", "path": "cms/2026/10/....jpg", "sha256": "...", "variants": [...], "alt", "credit", "sourceUrl", "tags", "width", "height"}`. The binary is fetched by the receiver from the origin node's public media URL (verifying `sha256`) or from shared object storage when configured (`CMS_MEDIA_DISK`, `CMS_MEDIA_URL`).
Subscribers and contact messages are Cloud-authoritative (they originate on the website) and are not synced to Local by default.

## 8. Operations

* Migrations are auto-discovered. `php artisan r007:cms-seed` (idempotent; also run by `r007:demo-seed`) seeds settings defaults and demo content. Stock photos: `database/seeders/stock/` (credits in `CREDITS.md`).
* Env: `CMS_WEB_URL` (email links), `CMS_MEDIA_DISK` (default `public`), `CMS_MEDIA_URL` (optional absolute base overriding the disk URL, e.g. a CDN), `CMS_SYNC_EMIT`, `MAIL_MAILER` (dev: `log`). Run `php artisan storage:link` once per node.
* Queue: the confirmation email is a queued mailable (`QUEUE_CONNECTION`).
