<?php

namespace App\Domain\Cms\Http\Controllers;

use App\Domain\Cms\Services\ContentService;
use App\Domain\Cms\Services\GalleryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AdminGalleryController
{
    public function __construct(private readonly GalleryService $gallery, private readonly ContentService $content) {}

    public function index(string $id): JsonResponse
    {
        return response()->json(['items' => $this->gallery->items($id), 'nextCursor' => null]);
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $items = $this->gallery->add($id, $request->json()->all());

        return response()->json(isset($request->json()->all()['items']) ? ['items' => $items] : $items[0], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return response()->json($this->gallery->update($id, $request->json()->all()));
    }

    public function destroy(string $id): Response
    {
        $this->gallery->delete($id);

        return response()->noContent();
    }

    public function reorder(Request $request, string $id): JsonResponse
    {
        $this->gallery->album($id);
        $d = $request->validate(AdminContentController::reorderRules());

        return response()->json(['updated' => $this->content->reorder('cms_gallery_item', 'gallery_item', $d['items'], 'album_id', $id)]);
    }
}
