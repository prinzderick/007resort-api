<?php

namespace App\Domain\Cms\Http\Controllers;

use App\Domain\Cms\Resources\ContentResource;
use App\Domain\Cms\Resources\ResourceRegistry;
use App\Domain\Cms\Services\ContentService;
use App\Domain\Cms\Support\MediaResolver;
use App\Support\Api\Concurrency;
use App\Support\Http\CursorPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** One controller for every managed content type; the route default `res` selects the {@see ContentResource}. */
class AdminContentController
{
    public function __construct(private readonly ContentService $content, private readonly MediaResolver $media) {}

    public function index(Request $request): JsonResponse
    {
        $res = ResourceRegistry::get($request->route()->defaults['res']);
        $q = DB::table($res->table());
        $res->filter($q, $request);
        [$col, $dir] = $res->order();
        $page = CursorPage::paginate($q, $request, $col, $dir, 50, 200);
        $body = $page->toArray(fn ($row) => $this->content->present($res, $row));
        if ($res->key() === 'home-sections') {
            $body['media'] = (object) $this->media->adminMap(array_column($body['items'], 'payload'));
        }

        return response()->json($body);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $res = ResourceRegistry::get($request->route()->defaults['res']);
        $row = $this->content->find($res, $id);
        $body = $this->content->present($res, $row);
        if ($res->key() === 'home-sections') {
            $body['media'] = (object) $this->media->adminMap($body['payload']);
        }

        return Concurrency::json($body, 200, (int) $row->row_version);
    }

    public function store(Request $request): JsonResponse
    {
        $res = ResourceRegistry::get($request->route()->defaults['res']);
        $d = $this->content->validate($res, $request->json()->all(), true);
        $body = $this->content->create($res, $d);

        return Concurrency::json($body, 201, $body['rowVersion'], ['Location' => url("/api/v1/admin/cms/{$res->key()}/{$body['id']}")]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $res = ResourceRegistry::get($request->route()->defaults['res']);
        $this->content->find($res, $id);
        $d = $this->content->validate($res, $request->json()->all(), false);
        [$body] = $this->content->update($res, $id, $d, $request);

        return Concurrency::json($body, 200, $body['rowVersion']);
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->content->delete(ResourceRegistry::get($request->route()->defaults['res']), $id);

        return response()->noContent();
    }

    public function transition(Request $request, string $id): JsonResponse
    {
        $res = ResourceRegistry::get($request->route()->defaults['res']);
        $this->content->find($res, $id);
        $action = $request->route()->defaults['action'];
        $at = null;
        if ($action === 'publish') {
            $at = Validator::make($request->json()->all(), ['publishedAt' => ['nullable', 'date']])->validate()['publishedAt'] ?? null;
        }
        $body = $this->content->transition($res, $id, $action, $at);

        return Concurrency::json($body, 200, $body['rowVersion']);
    }

    public function enable(Request $request, string $id): JsonResponse
    {
        $res = ResourceRegistry::get($request->route()->defaults['res']);
        $this->content->find($res, $id);
        $body = $this->content->setEnabled($res, $id, $request->route()->defaults['enabled']);

        return Concurrency::json($body, 200, $body['rowVersion']);
    }

    public function reorder(Request $request): JsonResponse
    {
        $res = ResourceRegistry::get($request->route()->defaults['res']);
        $d = $request->validate(self::reorderRules());
        $n = $this->content->reorder($res->table(), $res->entity(), $d['items']);

        return response()->json(['updated' => $n]);
    }

    /** @return array<string, list<string>> */
    public static function reorderRules(): array
    {
        return ['items' => ['required', 'array', 'min:1', 'max:200'], 'items.*.id' => ['required', 'uuid', 'distinct'], 'items.*.sortOrder' => ['required', 'integer', 'between:-1000000,1000000']];
    }
}
