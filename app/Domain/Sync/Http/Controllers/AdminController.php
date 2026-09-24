<?php

namespace App\Domain\Sync\Http\Controllers;

use App\Domain\Sync\Services\ConflictService;
use App\Domain\Sync\Services\SyncAdmin;
use App\Domain\Sync\Services\SyncStatusReport;
use App\Domain\Sync\Support\Ts;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Ids;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** IT/Admin surface (staff auth, permission config.manage): status, conflicts, failed outbox/inbox, retry/reprocess. */
class AdminController
{
    public function status(SyncStatusReport $report): JsonResponse
    {
        return response()->json($report->build());
    }

    public function conflicts(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'in:OPEN,RESOLVED'], 'category' => ['nullable', 'in:CONFIGURATION,PERMISSION,BOOKING,INVENTORY,ENTITY_VERSION']]);
        $q = DB::table('sync_conflict');
        foreach (['status', 'category'] as $f) {
            if ($v = $request->query($f)) {
                $q->where($f, $v);
            }
        }
        if ($v = $request->query('entityType')) {
            $q->where('entity_type', $v);
        }
        $page = CursorPage::paginate($q, $request, 'id', 'desc');

        return response()->json($page->toArray(fn ($r) => ConflictService::present($r)));
    }

    public function conflict(string $id): JsonResponse
    {
        $r = Ids::isUuid($id) ? DB::table('sync_conflict')->where('id', Ids::toBinary($id))->first() : null;
        if ($r === null) {
            throw ApiProblem::notFound('conflict_not_found', 'No such conflict.');
        }

        return response()->json(ConflictService::present($r));
    }

    public function resolveConflict(Request $request, string $id, SyncAdmin $admin): JsonResponse
    {
        $data = $request->validate(['resolution' => ['required', 'in:KEEP_LOCAL,MANUAL,DISMISSED'], 'note' => ['required', 'string', 'min:3', 'max:1000']]);
        $this->uuid($id);

        return response()->json($admin->resolveConflict($id, $data['resolution'], $data['note']));
    }

    public function reprocessConflict(string $id, SyncAdmin $admin): JsonResponse
    {
        $this->uuid($id);

        return response()->json($admin->reprocessConflict($id));
    }

    public function outbox(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'in:LOCAL,QUEUED,SYNCING,SYNCED,FAILED,CONFLICT']]);
        $q = DB::table('outbox_event');
        if ($v = $request->query('status')) {
            $q->where('sync_status', $v);
        }
        if ($v = $request->query('eventType')) {
            $q->where('event_type', $v);
        }
        $page = CursorPage::paginate($q, $request, 'seq', 'desc', idColumn: 'id');

        return response()->json($page->toArray(fn ($r) => [
            'eventId' => Ids::fromBinary($r->id), 'seq' => (int) $r->seq, 'eventType' => $r->event_type, 'entityType' => $r->entity_type,
            'entityId' => Ids::fromBinary($r->entity_id), 'entityVersion' => (int) $r->entity_version, 'syncStatus' => $r->sync_status,
            'retryCount' => (int) $r->retry_count, 'nextRetryAt' => Ts::iso($r->next_retry_at), 'lastAttemptAt' => Ts::iso($r->last_attempt_at),
            'lastError' => $r->last_error ? json_decode($r->last_error, true) : null, 'createdAt' => Ts::iso($r->created_at), 'syncedAt' => Ts::iso($r->synced_at),
        ]));
    }

    public function retryOutbox(string $id, SyncAdmin $admin): JsonResponse
    {
        $this->uuid($id);
        $admin->retryOutbox($id);

        return response()->json(['eventId' => $id, 'syncStatus' => 'QUEUED']);
    }

    public function replayFailed(SyncAdmin $admin): JsonResponse
    {
        return response()->json(['outboxRequeued' => $admin->replayFailedOutbox(), 'inbox' => $admin->replayFailedInbox()]);
    }

    public function inboxEvents(Request $request): JsonResponse
    {
        $request->validate(['result' => ['nullable', 'in:PENDING,APPLIED,CONFLICT,FAILED']]);
        $q = DB::table('inbox_event');
        if ($v = $request->query('result')) {
            $q->where('result', $v);
        }
        $page = CursorPage::paginate($q, $request, 'id', 'desc');

        return response()->json($page->toArray(fn ($r) => [
            'eventId' => Ids::fromBinary($r->id), 'eventType' => $r->event_type, 'sourceNode' => $r->source_node, 'entityType' => $r->entity_type,
            'entityId' => $r->entity_id ? Ids::fromBinary($r->entity_id) : null, 'entityVersion' => $r->entity_version === null ? null : (int) $r->entity_version,
            'result' => $r->result, 'attempts' => (int) $r->attempts, 'receivedAt' => Ts::iso($r->received_at), 'processedAt' => Ts::iso($r->processed_at),
            'nextAttemptAt' => Ts::iso($r->next_attempt_at), 'lastError' => $r->last_error ? json_decode($r->last_error, true) : null,
        ]));
    }

    public function reprocessInbox(string $id, SyncAdmin $admin): JsonResponse
    {
        $this->uuid($id);

        return response()->json($admin->reprocessInbox($id)->toArray());
    }

    private function uuid(string $id): void
    {
        if (! Ids::isUuid($id)) {
            throw ApiProblem::notFound();
        }
    }
}
