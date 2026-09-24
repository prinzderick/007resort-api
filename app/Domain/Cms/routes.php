<?php

use App\Domain\Cms\Http\Controllers\AdminContentController as Content;
use App\Domain\Cms\Http\Controllers\AdminController as Admin;
use App\Domain\Cms\Http\Controllers\AdminGalleryController as Gallery;
use App\Domain\Cms\Http\Controllers\AdminInboxController as Inbox;
use App\Domain\Cms\Http\Controllers\PublicController as Pub;
use Illuminate\Support\Facades\Route;

// Loaded under /api/v1. Contract: docs/CMS_API.md.

// ---- Public (website service token r7s_..., or customer/staff token) --------------------------------------------------------------
Route::middleware(['auth:staff,customer,service', 'throttle:customer-api'])->prefix('public/cms')->group(function () {
    Route::get('site', [Pub::class, 'site']);
    Route::get('home', [Pub::class, 'home']);
    Route::get('pages', [Pub::class, 'pages']);
    Route::get('pages/{slug}', [Pub::class, 'page']);
    Route::get('posts', [Pub::class, 'posts']);
    Route::get('posts/{slug}', [Pub::class, 'post']);
    Route::get('post-categories', [Pub::class, 'postCategories']);
    Route::get('events', [Pub::class, 'events']);
    Route::get('events/{slug}', [Pub::class, 'event']);
    Route::get('gallery/albums', [Pub::class, 'albums']);
    Route::get('gallery/albums/{slug}', [Pub::class, 'album']);
    Route::get('sitemap', [Pub::class, 'sitemap']);

    Route::post('subscribers', [Pub::class, 'subscribe'])->middleware('throttle:cms-subscribe');
    Route::get('subscribers/confirm/{token}', [Pub::class, 'confirmPreview'])->middleware('throttle:cms-token');
    Route::post('subscribers/confirm/{token}', [Pub::class, 'confirm'])->middleware('throttle:cms-token');
    Route::post('subscribers/unsubscribe/{token}', [Pub::class, 'unsubscribe'])->middleware('throttle:cms-token');
    Route::post('contact', [Pub::class, 'contact'])->middleware('throttle:cms-contact');
});

// ---- Admin (staff, permission based) ---------------------------------------------------------------------------------------------------
Route::middleware(['auth:staff', 'device:optional'])->prefix('admin/cms')->group(function () {
    Route::middleware('permission:cms.view')->group(function () {
        Route::get('meta', [Admin::class, 'meta']);
        Route::get('summary', [Admin::class, 'summary']);
        Route::post('render-preview', [Admin::class, 'renderPreview']);
        Route::get('settings', [Admin::class, 'settings']);
        Route::get('settings/{group}', [Admin::class, 'setting']);
        Route::get('media', [Admin::class, 'mediaIndex']);
        Route::get('media/{id}', [Admin::class, 'mediaShow']);
        Route::get('media/{id}/usage', [Admin::class, 'mediaUsage']);
        Route::get('gallery/albums/{id}/items', [Gallery::class, 'index']);
    });
    Route::put('settings/{group}', [Admin::class, 'updateSetting'])->middleware('permission:cms.manage');

    Route::middleware('permission:cms.media.manage')->group(function () {
        Route::post('media', [Admin::class, 'mediaUpload'])->middleware('idempotent');
        Route::patch('media/{id}', [Admin::class, 'mediaUpdate']);
        Route::delete('media/{id}', [Admin::class, 'mediaDelete']);
    });

    // Generic content types: pages, posts, events, gallery albums (with publish state); post categories, home sections (without).
    foreach (['pages' => 'pages', 'posts' => 'posts', 'events' => 'events', 'gallery/albums' => 'gallery/albums', 'post-categories' => 'post-categories', 'home-sections' => 'home-sections'] as $path => $res) {
        Route::get($path, [Content::class, 'index'])->defaults('res', $res)->middleware('permission:cms.view');
        Route::middleware('permission:cms.manage')->group(function () use ($path, $res) {
            if (in_array($res, ['post-categories', 'home-sections', 'gallery/albums'], true)) {
                Route::post("$path/reorder", [Content::class, 'reorder'])->defaults('res', $res);
            }
            Route::post($path, [Content::class, 'store'])->defaults('res', $res)->middleware('idempotent');
            Route::patch("$path/{id}", [Content::class, 'update'])->defaults('res', $res);
            Route::delete("$path/{id}", [Content::class, 'destroy'])->defaults('res', $res);
        });
        Route::get("$path/{id}", [Content::class, 'show'])->defaults('res', $res)->middleware('permission:cms.view');
        if (! in_array($res, ['post-categories', 'home-sections'], true)) {
            foreach (['publish', 'unpublish', 'archive'] as $action) {
                Route::post("$path/{id}/$action", [Content::class, 'transition'])->defaults('res', $res)->defaults('action', $action)->middleware('permission:cms.publish');
            }
        }
    }
    Route::post('home-sections/{id}/enable', [Content::class, 'enable'])->defaults('res', 'home-sections')->defaults('enabled', true)->middleware('permission:cms.publish');
    Route::post('home-sections/{id}/disable', [Content::class, 'enable'])->defaults('res', 'home-sections')->defaults('enabled', false)->middleware('permission:cms.publish');

    Route::middleware('permission:cms.manage')->group(function () {
        Route::post('gallery/albums/{id}/items', [Gallery::class, 'store'])->middleware('idempotent');
        Route::post('gallery/albums/{id}/items/reorder', [Gallery::class, 'reorder']);
        Route::patch('gallery/items/{id}', [Gallery::class, 'update']);
        Route::delete('gallery/items/{id}', [Gallery::class, 'destroy']);
        Route::post('subscribers/{id}/unsubscribe', [Inbox::class, 'unsubscribe']);
        Route::delete('subscribers/{id}', [Inbox::class, 'eraseSubscriber']);
    });

    Route::get('subscribers', [Inbox::class, 'subscribers'])->middleware('permission:cms.subscribers.view');
    Route::get('subscribers/export', [Inbox::class, 'export'])->middleware('permission:cms.subscribers.export');
    Route::middleware('permission:cms.messages.manage')->group(function () {
        Route::get('messages', [Inbox::class, 'messages']);
        Route::get('messages/{id}', [Inbox::class, 'message']);
        Route::patch('messages/{id}', [Inbox::class, 'updateMessage']);
        Route::delete('messages/{id}', [Inbox::class, 'eraseMessage']);
    });
});
