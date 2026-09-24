<?php

namespace App\Domain\Config\Http\Controllers;

use App\Domain\Config\Services\SearchService;
use App\Domain\Config\Services\SetupStatusService;
use App\Support\Http\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InsightController
{
    public function setupStatus(SetupStatusService $s): JsonResponse
    {
        return response()->json($s->status());
    }

    public function search(Request $request, SearchService $search): JsonResponse
    {
        $d = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100'], 'types' => ['sometimes', 'nullable', 'string', 'max:200'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:20']]);
        $types = isset($d['types']) && $d['types'] !== '' ? array_values(array_filter(array_map('trim', explode(',', $d['types'])))) : null;
        if ($types !== null && ($bad = array_diff($types, array_keys(SearchService::TYPES))) !== []) {
            throw ApiProblem::unprocessable('validation_failed', 'Unknown result type.', ['types' => ['Unknown: '.implode(', ', $bad).'. Allowed: '.implode(', ', array_keys(SearchService::TYPES)).'.']]);
        }

        return response()->json($search->search(trim($d['q']), $types, (int) ($d['limit'] ?? 5)));
    }
}
