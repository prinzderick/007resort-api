<?php

namespace App\Domain\Orders\Http\Controllers;

use App\Domain\Orders\Services\BillService;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillController
{
    public function __construct(private readonly BillService $bills) {}

    /** POST /orders/{id}/bill */
    public function print(Request $request, string $orderId): JsonResponse
    {
        $d = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        return response()->json($this->bills->print($this->id($orderId), $d['note'] ?? null));
    }

    /** POST /orders/{id}/bill/cancel */
    public function cancel(Request $request, string $orderId): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $r = $this->bills->cancel($this->id($orderId), $d['reason'], $request->header('X-Step-Up-Token'));
        if ($r['done']) {
            return response()->json($r['order']);
        }

        return response()->json(['status' => 'PENDING_APPROVAL', 'approval' => $r['approval'], 'order' => $r['order']], 202);
    }

    private function id(string $id): string
    {
        return Ids::isUuid($id) ? Ids::normalize($id) : throw ApiProblem::notFound();
    }
}
