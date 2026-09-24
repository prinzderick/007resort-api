<?php

namespace App\Domain\Cms\Http\Controllers;

use App\Domain\Cms\Services\ContactService;
use App\Domain\Cms\Services\SubscriberService;
use App\Domain\Cms\Support\Cms;
use App\Domain\Cms\Support\CmsAudit;
use App\Domain\Cms\Support\Rows;
use App\Support\Http\CursorPage;
use App\Support\Ids;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Subscribers (list, export, unsubscribe, erase) and the contact-message inbox. */
class AdminInboxController
{
    public function __construct(private readonly SubscriberService $subs, private readonly ContactService $contact) {}

    private function like(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
    }

    private function subscriberQuery(Request $request): Builder
    {
        $q = DB::table('cms_subscriber');
        if (in_array($s = strtoupper((string) $request->query('status')), Cms::SUBSCRIBER_STATUSES, true)) {
            $q->where('status', $s);
        }
        if (in_array($src = (string) $request->query('source'), Cms::SUBSCRIBER_SOURCES, true)) {
            $q->where('source', $src);
        }
        if (($t = trim((string) $request->query('q'))) !== '') {
            $q->where(fn ($w) => $w->where('email', 'like', $this->like($t))->orWhere('name', 'like', $this->like($t)));
        }

        return $q;
    }

    public function subscribers(Request $request): JsonResponse
    {
        $body = CursorPage::paginate($this->subscriberQuery($request), $request, 'created_at', 'desc', 50, 200)->toArray(fn ($r) => $this->subs->present($r));
        $counts = ['pending' => 0, 'confirmed' => 0, 'unsubscribed' => 0];
        foreach (DB::table('cms_subscriber')->selectRaw('status, COUNT(*) n')->groupBy('status')->get() as $r) {
            $counts[strtolower($r->status)] = (int) $r->n;
        }
        $body['counts'] = $counts;

        return response()->json($body);
    }

    public function export(Request $request): StreamedResponse
    {
        $q = $this->subscriberQuery($request)->orderBy('created_at')->orderBy('id');
        $count = (clone $q)->count();
        CmsAudit::record('cms.subscriber.export', 'CmsSubscriber', Ids::uuid7(), null, ['rows' => $count, 'status' => $request->query('status'), 'source' => $request->query('source')]);
        $safe = fn ($v) => is_string($v) && $v !== '' && str_contains("=+-@\t\r", $v[0]) ? "'".$v : $v;

        return response()->streamDownload(function () use ($q, $safe) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'name', 'source', 'status', 'consentedAt', 'confirmedAt', 'unsubscribedAt'], ',', '"', '');
            foreach ($q->cursor() as $r) {
                fputcsv($out, [$safe($r->email), $safe($r->name), $r->source, $r->status, Rows::iso($r->consented_at), Rows::iso($r->confirmed_at), Rows::iso($r->unsubscribed_at)], ',', '"', '');
            }
            fclose($out);
        }, 'subscribers-'.now('UTC')->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public function unsubscribe(string $id): JsonResponse
    {
        return response()->json($this->subs->adminUnsubscribe($id));
    }

    public function eraseSubscriber(string $id): Response
    {
        $this->subs->erase($id);

        return response()->noContent();
    }

    public function messages(Request $request): JsonResponse
    {
        $q = DB::table('cms_contact_message');
        if (in_array($s = strtoupper((string) $request->query('status')), Cms::CONTACT_STATUSES, true)) {
            $q->where('status', $s);
        }
        if (in_array($t = strtoupper((string) $request->query('topic')), Cms::CONTACT_TOPICS, true)) {
            $q->where('topic', $t);
        }
        if (($term = trim((string) $request->query('q'))) !== '') {
            $q->where(fn ($w) => $w->where('email', 'like', $this->like($term))->orWhere('name', 'like', $this->like($term))->orWhere('message', 'like', $this->like($term)));
        }
        $body = CursorPage::paginate($q, $request, 'created_at', 'desc', 50, 200)->toArray(fn ($r) => $this->contact->present($r));
        $body['counts'] = $this->contact->counts();

        return response()->json($body);
    }

    public function message(string $id): JsonResponse
    {
        return response()->json($this->contact->present($this->contact->find($id)));
    }

    public function updateMessage(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['status' => ['sometimes', Rule::in(Cms::CONTACT_STATUSES)], 'internalNote' => ['sometimes', 'nullable', 'string', 'max:1000']]);

        return response()->json($this->contact->update($id, $d));
    }

    public function eraseMessage(string $id): Response
    {
        $this->contact->erase($id);

        return response()->noContent();
    }
}
