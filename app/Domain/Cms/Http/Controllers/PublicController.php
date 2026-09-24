<?php

namespace App\Domain\Cms\Http\Controllers;

use App\Domain\Cms\Services\ContactService;
use App\Domain\Cms\Services\PublicContentService;
use App\Domain\Cms\Services\SubscriberService;
use App\Domain\Cms\Support\Cms;
use App\Domain\Customer\Support\Actor;
use App\Domain\Identity\Services\PermissionChecker;
use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class PublicController
{
    public function __construct(private readonly PublicContentService $content, private readonly SubscriberService $subs, private readonly ContactService $contact) {}

    /** Staff holding `cms.view` may preview drafts with `?preview=true`. */
    private function preview(Request $request): bool
    {
        return $request->boolean('preview') && Actor::isStaff() && app(PermissionChecker::class)->can((string) RequestContext::staffId(), 'cms.view');
    }

    /** JSON with a strong ETag (304 on If-None-Match) and shared-cache headers; previews are never cached. */
    private function cached(Request $request, array $data, bool $preview = false): Response
    {
        $etag = '"'.substr(hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 32).'"';
        $cc = $preview ? 'private, no-store' : 'public, max-age='.(int) config('cms.cache_seconds', 60).', stale-while-revalidate=300';
        if (! $preview && trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, ['ETag' => $etag, 'Cache-Control' => $cc]);
        }

        return response()->json($data, 200, ['ETag' => $etag, 'Cache-Control' => $cc]);
    }

    public function site(Request $request): Response
    {
        return $this->cached($request, $this->content->site());
    }

    public function home(Request $request): Response
    {
        return $this->cached($request, $this->content->home());
    }

    public function pages(Request $request): Response
    {
        return $this->cached($request, $this->content->pages($p = $this->preview($request)), $p);
    }

    public function page(Request $request, string $slug): Response
    {
        return $this->cached($request, $this->content->page($slug, $p = $this->preview($request)), $p);
    }

    public function posts(Request $request): Response
    {
        return $this->cached($request, $this->content->posts($request, $p = $this->preview($request)), $p);
    }

    public function post(Request $request, string $slug): Response
    {
        return $this->cached($request, $this->content->post($slug, $p = $this->preview($request)), $p);
    }

    public function postCategories(Request $request): Response
    {
        return $this->cached($request, $this->content->postCategories($request->boolean('all'), $p = $this->preview($request)), $p);
    }

    public function events(Request $request): Response
    {
        return $this->cached($request, $this->content->events($request, $p = $this->preview($request)), $p);
    }

    public function event(Request $request, string $slug): Response
    {
        return $this->cached($request, $this->content->event($slug, $p = $this->preview($request)), $p);
    }

    public function albums(Request $request): Response
    {
        return $this->cached($request, $this->content->albums($p = $this->preview($request)), $p);
    }

    public function album(Request $request, string $slug): Response
    {
        return $this->cached($request, $this->content->album($slug, $p = $this->preview($request)), $p);
    }

    public function sitemap(Request $request): Response
    {
        return $this->cached($request, $this->content->sitemap());
    }

    // ---- writes ----

    /** The visitor's IP: the BFF forwards it in X-Client-IP (trusted for service tokens only). */
    public static function clientIp(Request $request): string
    {
        $fwd = (string) $request->header('X-Client-IP');
        if (Actor::isService() && filter_var($fwd, FILTER_VALIDATE_IP) !== false) {
            return $fwd;
        }

        return (string) $request->ip();
    }

    public function subscribe(Request $request): JsonResponse
    {
        $d = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:190'], 'name' => ['nullable', 'string', 'max:120'], 'source' => ['nullable', Rule::in(Cms::SUBSCRIBER_SOURCES)],
            'consent' => ['required', 'accepted'], 'consentText' => ['nullable', 'string', 'max:500'], 'website' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->subs->subscribe($d, self::clientIp($request)));
    }

    public function confirmPreview(string $token): JsonResponse
    {
        return response()->json($this->subs->preview($token));
    }

    public function confirm(string $token): JsonResponse
    {
        return response()->json($this->subs->confirm($token));
    }

    public function unsubscribe(string $token): JsonResponse
    {
        return response()->json($this->subs->unsubscribe($token));
    }

    public function contact(Request $request): JsonResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'], 'email' => ['required', 'string', 'email:rfc', 'max:190'], 'phone' => ['nullable', 'string', 'max:40'],
            'topic' => ['nullable', Rule::in(Cms::CONTACT_TOPICS)], 'message' => ['required', 'string', 'min:10', 'max:5000'], 'website' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->contact->submit($d, self::clientIp($request), $request->userAgent()), 201);
    }
}
