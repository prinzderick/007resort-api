<?php

namespace App\Domain\Cms\Http\Controllers;

use App\Domain\Cms\Services\MediaService;
use App\Domain\Cms\Services\MediaUsage;
use App\Domain\Cms\Services\SettingsService;
use App\Domain\Cms\Support\Cms;
use App\Domain\Cms\Support\HomeSectionTypes;
use App\Domain\Cms\Support\Markdown;
use App\Domain\Cms\Support\MediaResolver;
use App\Support\Api\Concurrency;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Ids;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Meta, summary, markdown preview, settings and media. */
class AdminController
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly MediaService $mediaService,
        private readonly MediaUsage $usage,
        private readonly MediaResolver $media,
    ) {}

    public function meta(): JsonResponse
    {
        return response()->json([
            'statuses' => Cms::STATUSES, 'eventCategories' => Cms::EVENT_CATEGORIES, 'homeSectionTypes' => HomeSectionTypes::describe(), 'settingGroups' => Cms::SETTING_GROUPS,
            'contactTopics' => Cms::CONTACT_TOPICS, 'contactStatuses' => Cms::CONTACT_STATUSES, 'subscriberSources' => Cms::SUBSCRIBER_SOURCES, 'subscriberStatuses' => Cms::SUBSCRIBER_STATUSES,
            'timezone' => Cms::tz(), 'media' => ['maxBytes' => Cms::MEDIA_MAX_BYTES, 'mimeTypes' => Cms::MEDIA_MIME],
        ]);
    }

    public function summary(): JsonResponse
    {
        $by = fn (string $t) => ['draft' => DB::table($t)->where('status', 'DRAFT')->count(), 'published' => DB::table($t)->where('status', 'PUBLISHED')->count(), 'archived' => DB::table($t)->where('status', 'ARCHIVED')->count()];
        $count = fn (string $t, string $col, string $v) => DB::table($t)->where($col, $v)->count();

        return response()->json([
            'pages' => $by('cms_page'), 'posts' => $by('cms_post'), 'events' => $by('cms_event'), 'albums' => $by('cms_gallery_album'),
            'homeSections' => ['enabled' => DB::table('cms_home_section')->where('is_enabled', 1)->count(), 'disabled' => DB::table('cms_home_section')->where('is_enabled', 0)->count()],
            'subscribers' => ['pending' => $count('cms_subscriber', 'status', 'PENDING'), 'confirmed' => $count('cms_subscriber', 'status', 'CONFIRMED'), 'unsubscribed' => $count('cms_subscriber', 'status', 'UNSUBSCRIBED')],
            'messages' => ['new' => $count('cms_contact_message', 'status', 'NEW')], 'media' => ['count' => DB::table('cms_media')->count()],
        ]);
    }

    public function renderPreview(Request $request): JsonResponse
    {
        $d = $request->validate(['markdown' => ['present', 'nullable', 'string', 'max:200000']]);

        return response()->json(['html' => Markdown::html($d['markdown'])]);
    }

    // ---- settings ----

    public function settings(): JsonResponse
    {
        $groups = [];
        $values = [];
        foreach ($this->settings->rows() as $g => $row) {
            $s = $this->settings->shape($row);
            $groups[$g] = ['value' => $s['value'], 'rowVersion' => $s['rowVersion'], 'updatedAt' => $s['updatedAt']];
            $values[$g] = $s['value'];
        }

        return response()->json(['groups' => $groups, 'media' => (object) $this->media->adminMap($values)]);
    }

    public function setting(string $group): JsonResponse
    {
        $s = $this->settings->shape($this->settings->row($group));
        $s['media'] = (object) $this->media->adminMap($s['value']);

        return Concurrency::json($s, 200, $s['rowVersion']);
    }

    public function updateSetting(Request $request, string $group): JsonResponse
    {
        $d = $request->json()->all();
        if (! isset($d['value']) || ! is_array($d['value'])) {
            throw ApiProblem::unprocessable('validation_failed', 'Body must be {"value": {...}}.', ['value' => ['The value field is required.']]);
        }
        $s = $this->settings->replace($group, $d['value'], $request);
        $s['media'] = (object) $this->media->adminMap($s['value']);

        return Concurrency::json($s, 200, $s['rowVersion']);
    }

    // ---- media ----

    public function mediaIndex(Request $request): JsonResponse
    {
        $q = DB::table('cms_media');
        if (($term = trim((string) $request->query('q'))) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $q->where(fn ($w) => $w->where('alt', 'like', $like)->orWhere('original_name', 'like', $like)->orWhere('credit', 'like', $like));
        }
        if (($tag = trim((string) $request->query('tag'))) !== '') {
            $q->whereJsonContains('tags', mb_strtolower($tag));
        }

        return response()->json(CursorPage::paginate($q, $request, 'created_at', 'desc', 50, 200)->toArray(fn ($r) => $this->mediaService->adminShape($r)));
    }

    public function mediaShow(string $id): JsonResponse
    {
        $row = $this->mediaRow($id);

        return Concurrency::json($this->mediaService->adminShape($row), 200, (int) $row->row_version);
    }

    public function mediaUpload(Request $request): JsonResponse
    {
        $d = $request->validate([
            'file' => ['required', 'file'], 'alt' => ['nullable', 'string', 'max:300'], 'credit' => ['nullable', 'string', 'max:300'],
            'sourceUrl' => ['nullable', 'url:http,https', 'max:500'], 'tags' => ['nullable', 'array', 'max:20'], 'tags.*' => ['string', 'max:32'],
        ]);
        $d['tags'] = array_values(array_unique(array_map(fn ($t) => mb_strtolower(trim($t)), $d['tags'] ?? [])));
        $m = $this->mediaService->upload($request->file('file'), $d);

        return Concurrency::json($m, 201, $m['rowVersion'], ['Location' => url('/api/v1/admin/cms/media/'.$m['id'])]);
    }

    public function mediaUpdate(Request $request, string $id): JsonResponse
    {
        $this->mediaRow($id);
        $d = $request->validate([
            'alt' => ['sometimes', 'nullable', 'string', 'max:300'], 'credit' => ['sometimes', 'nullable', 'string', 'max:300'],
            'sourceUrl' => ['sometimes', 'nullable', 'url:http,https', 'max:500'], 'tags' => ['sometimes', 'nullable', 'array', 'max:20'], 'tags.*' => ['string', 'max:32'],
        ]);
        $m = $this->mediaService->update($id, $d);

        return Concurrency::json($m, 200, $m['rowVersion']);
    }

    public function mediaUsage(string $id): JsonResponse
    {
        $this->mediaRow($id);
        $u = $this->usage->of($id);

        return response()->json(['usageCount' => count($u), 'usage' => $u]);
    }

    public function mediaDelete(string $id): Response
    {
        $this->mediaRow($id);
        $this->mediaService->delete($id);

        return response()->noContent();
    }

    private function mediaRow(string $id): object
    {
        return (Ids::isUuid($id) ? DB::table('cms_media')->where('id', Ids::toBinary($id))->first() : null) ?? throw ApiProblem::notFound('not_found', 'Media not found.');
    }
}
