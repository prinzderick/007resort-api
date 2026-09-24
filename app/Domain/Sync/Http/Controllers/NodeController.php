<?php

namespace App\Domain\Sync\Http\Controllers;

use App\Domain\Sync\Services\HeartbeatService;
use App\Domain\Sync\Services\InboxProcessor;
use App\Domain\Sync\Services\PullService;
use App\Domain\Sync\Support\InboundEvent;
use App\Support\Http\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Node-to-node endpoints (behind `node.auth`): inbox (push), pull + ack (Cloud -> Local), heartbeat. */
class NodeController
{
    /** POST /sync/inbox — batch of events; per-event result. Always 200 when the batch was evaluated. */
    public function inbox(Request $request, InboxProcessor $processor): JsonResponse
    {
        if (! config('sync.accept_push')) {
            throw ApiProblem::notFound('sync_inbox_disabled', 'This node does not accept pushed sync events (the Local node only pulls).');
        }
        $data = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:'.(int) config('sync.inbox_max_batch')],
            'events.*.eventId' => ['required', 'uuid'],
            'events.*.eventType' => ['required', 'string', 'max:64'],
            'events.*.entityType' => ['required', 'string', 'max:64'],
            'events.*.entityId' => ['required', 'uuid'],
            'events.*.entityVersion' => ['required', 'integer', 'min:0'],
            'events.*.organizationId' => ['nullable', 'uuid'],
            'events.*.siteId' => ['nullable', 'uuid'],
            'events.*.facilityId' => ['nullable', 'uuid'],
            'events.*.sourceNode' => ['required', 'in:local,cloud'],
            'events.*.occurredAt' => ['required', 'date'],
            'events.*.payload' => ['present', 'array'],
        ]);

        $peer = $request->attributes->get('sync.node');
        $bound = $request->attributes->get('sync.site');
        $results = [];
        foreach ($data['events'] as $envelope) {
            $event = InboundEvent::fromEnvelope($envelope);
            if ($event->sourceNode !== $peer) {
                throw ApiProblem::forbidden('node_mismatch', 'sourceNode does not match the authenticated node credential.');
            }
            if ($bound !== null && $event->siteId !== null && $event->siteId !== $bound) {
                throw ApiProblem::forbidden('node_site_mismatch', 'This node credential is not valid for that site.');
            }
            $results[] = $processor->receive($event)->toArray();
        }

        return response()->json(['results' => $results]);
    }

    /** GET /sync/pull — events the peer has queued for us (Local polls Cloud). */
    public function pull(Request $request, PullService $pull): JsonResponse
    {
        if (! config('sync.serve_pull')) {
            throw ApiProblem::notFound('sync_pull_disabled', 'This node does not serve pull requests.');
        }
        $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:500'], 'cursor' => ['nullable', 'string', 'max:32'], 'since' => ['nullable', 'string', 'max:32'], 'siteId' => ['nullable', 'uuid']]);
        $site = $request->attributes->get('sync.site') ?? ($request->query('siteId') ?: null);
        try {
            $page = $pull->serve((int) ($request->query('limit') ?: config('sync.pull_batch')), $request->query('cursor') ?: $request->query('since'), $site);
        } catch (\InvalidArgumentException) {
            throw ApiProblem::badRequest('invalid_cursor', 'The pull cursor is invalid.');
        }

        return response()->json($page);
    }

    /** POST /sync/pull/ack — the puller reports what it did with each served event. */
    public function ack(Request $request, PullService $pull): JsonResponse
    {
        if (! config('sync.serve_pull')) {
            throw ApiProblem::notFound('sync_pull_disabled', 'This node does not serve pull requests.');
        }
        $data = $request->validate([
            'results' => ['required', 'array', 'min:1', 'max:500'],
            'results.*.eventId' => ['required', 'uuid'],
            'results.*.result' => ['required', 'in:APPLIED,DUPLICATE,CONFLICT,DEFERRED,FAILED'],
            'results.*.detail' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['acknowledged' => $pull->acknowledge($data['results'])]);
    }

    /** POST /sync/heartbeat */
    public function heartbeat(Request $request, HeartbeatService $heartbeat): JsonResponse
    {
        if (! config('sync.accept_heartbeat')) {
            throw ApiProblem::notFound('sync_heartbeat_disabled', 'This node does not accept heartbeats.');
        }
        $data = $request->validate([
            'nodeId' => ['required', 'uuid'],
            'sentAt' => ['required', 'date'],
            'appVersion' => ['nullable', 'string', 'max:32'],
            'outboxDepth' => ['nullable', 'integer', 'min:0'],
            'oldestUnsyncedAt' => ['nullable', 'date'],
            'lastPulledCursor' => ['nullable', 'string', 'max:64'],
            'lastSyncAt' => ['nullable', 'date'],
            'services' => ['nullable', 'array'],
            'services.*' => ['string', 'in:ok,degraded,down'],
        ]);

        return response()->json($heartbeat->receive($data, $request->attributes->get('sync.site')));
    }
}
