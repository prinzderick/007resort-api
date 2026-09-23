<?php

namespace App\Domain\Payments\Http\Controllers;

use App\Domain\Identity\Auth\Scope;
use App\Domain\Identity\Services\PermissionChecker;
use App\Domain\Payments\Services\ReceiptService;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReceiptController
{
    public function __construct(private readonly ReceiptService $receipts, private readonly PermissionChecker $permissions) {}

    /** GET /receipts/{receiptId}[?reprint=true] */
    public function show(Request $request, string $receiptId): JsonResponse
    {
        $row = $this->receipts->find(Ids::normalize($receiptId)) ?? throw ApiProblem::notFound('not_found', 'Receipt not found.');

        return $this->respond($request, $row);
    }

    /** GET /orders/{orderId}/receipt */
    public function forOrder(Request $request, string $orderId): JsonResponse
    {
        $row = $this->receipts->latestForOrder(Ids::normalize($orderId)) ?? throw ApiProblem::notFound('not_found', 'No receipt for that order.');

        return $this->respond($request, $row);
    }

    private function respond(Request $request, object $row): JsonResponse
    {
        $staff = RequestContext::staffId() ?? throw ApiProblem::unauthenticated();
        $scope = Scope::facility(Ids::fromBinary($row->facility_unit_id));
        if (! $this->permissions->can($staff, 'receipt.view', $scope)) {
            throw ApiProblem::permissionDenied('receipt.view');
        }
        $reprint = $request->boolean('reprint');
        if ($reprint) {
            if (! $this->permissions->can($staff, 'receipt.reprint', $scope)) {
                throw ApiProblem::permissionDenied('receipt.reprint');
            }
            $this->receipts->recordReprint($row);
        }

        return response()->json($this->receipts->present($row, $reprint));
    }
}
