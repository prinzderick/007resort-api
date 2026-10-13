<?php

namespace App\Domain\Orders\Http\Controllers;

use App\Domain\Orders\Services\WaiterFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Waiter-only service flow: open tabs board, hand-overs, supervisor reassignment (settle lives in Payments). */
class WaiterFlowController
{
    public function __construct(private readonly WaiterFlowService $flow) {}

    /** GET /waiter-flow/open-items?facilityId=&staffId= */
    public function openItems(Request $request): JsonResponse
    {
        $q = $request->validate(['facilityId' => ['required', 'uuid'], 'staffId' => ['nullable', 'uuid']]);

        return response()->json($this->flow->openItems(strtolower($q['facilityId']), isset($q['staffId']) ? strtolower($q['staffId']) : null));
    }

    /** GET /waiter-flow/waiters?facilityId= : colleagues a tab can be handed to */
    public function waiters(Request $request): JsonResponse
    {
        $q = $request->validate(['facilityId' => ['required', 'uuid']]);

        return response()->json(['items' => $this->flow->waiters(strtolower($q['facilityId']))]);
    }

    /** GET /waiter-flow/handovers?facilityId=&direction=incoming|outgoing|all&status=PENDING */
    public function handovers(Request $request): JsonResponse
    {
        $q = $request->validate(['facilityId' => ['required', 'uuid'], 'direction' => ['nullable', 'in:incoming,outgoing,all'], 'status' => ['nullable', 'string', 'max:80']]);

        return response()->json(['items' => $this->flow->listHandovers($q['facilityId'], $q['direction'] ?? 'all', array_key_exists('status', $q) ? $q['status'] : 'PENDING')]);
    }

    /** POST /waiter-flow/handovers */
    public function request(Request $request): JsonResponse
    {
        $d = $request->validate([
            'facilityId' => ['required', 'uuid'], 'toStaffId' => ['required', 'uuid'], 'note' => ['nullable', 'string', 'max:255'],
            'orderIds' => ['nullable', 'array', 'min:1', 'max:200'], 'orderIds.*' => ['uuid', 'distinct'],
        ]);

        return response()->json($this->flow->requestHandover($d['facilityId'], $d['toStaffId'], $d['orderIds'] ?? null, $d['note'] ?? null), 201);
    }

    public function accept(string $handoverId): JsonResponse
    {
        return response()->json($this->flow->accept($handoverId));
    }

    public function reject(Request $request, string $handoverId): JsonResponse
    {
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return response()->json($this->flow->reject($handoverId, $d['reason'] ?? null));
    }

    public function cancel(string $handoverId): JsonResponse
    {
        return response()->json($this->flow->cancel($handoverId));
    }

    /** POST /waiter-flow/reassign (supervisor/manager) */
    public function reassign(Request $request): JsonResponse
    {
        $d = $request->validate([
            'facilityId' => ['required', 'uuid'], 'toStaffId' => ['required', 'uuid'], 'fromStaffId' => ['nullable', 'uuid'],
            'orderIds' => ['nullable', 'array', 'min:1', 'max:200'], 'orderIds.*' => ['uuid', 'distinct'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        return response()->json($this->flow->reassign($d['facilityId'], $d['toStaffId'], $d['fromStaffId'] ?? null, $d['orderIds'] ?? null, $d['reason']));
    }
}
