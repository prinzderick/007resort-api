<?php

namespace App\Domain\Orders\Http\Controllers;

use App\Domain\Orders\Approvals\ApprovalService;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalController
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->approvals->list($request));
    }

    public function show(string $approvalId): JsonResponse
    {
        return response()->json($this->approvals->present($this->approvals->find($this->id($approvalId))));
    }

    public function decide(Request $request, string $approvalId): JsonResponse
    {
        $d = $request->validate(['decision' => ['required', 'in:APPROVE,REJECT'], 'note' => ['nullable', 'string', 'max:500'], 'stepUpToken' => ['nullable', 'string', 'max:255']]);

        return response()->json($this->approvals->decide($this->id($approvalId), $d['decision'], $d['note'] ?? null, $d['stepUpToken'] ?? $request->header('X-Step-Up-Token')));
    }

    public function cancel(string $approvalId): JsonResponse
    {
        return response()->json($this->approvals->cancel($this->id($approvalId)));
    }

    private function id(string $id): string
    {
        return Ids::isUuid($id) ? Ids::normalize($id) : throw ApiProblem::notFound();
    }
}
