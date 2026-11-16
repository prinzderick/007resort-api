<?php

namespace App\Domain\Payments\Http\Controllers;

use App\Domain\Payments\Services\PrintJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrintJobController
{
    public function __construct(private readonly PrintJobService $jobs) {}

    /** POST /receipts/{receiptId}/print-jobs */
    public function request(Request $request, string $receiptId): JsonResponse
    {
        $d = $request->validate(['copies' => ['nullable', 'integer', 'min:1', 'max:5']]);

        return response()->json($this->jobs->request($receiptId, (int) ($d['copies'] ?? 1)), 201);
    }

    /** GET /print-jobs?facilityId=&status=QUEUED */
    public function index(Request $request): JsonResponse
    {
        $d = $request->validate(['facilityId' => ['required', 'uuid'], 'status' => ['nullable', 'string', 'max:60']]);

        return response()->json(['items' => $this->jobs->queue($d['facilityId'], $d['status'] ?? null)]);
    }

    public function show(string $jobId): JsonResponse
    {
        return response()->json($this->jobs->show($jobId));
    }

    public function printed(string $jobId): JsonResponse
    {
        return response()->json($this->jobs->markPrinted($jobId));
    }

    public function failed(Request $request, string $jobId): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return response()->json($this->jobs->markFailed($jobId, $d['reason']));
    }

    public function cancel(string $jobId): JsonResponse
    {
        return response()->json($this->jobs->cancel($jobId));
    }
}
