<?php

namespace App\Domain\Config\Http\Controllers;

use App\Domain\Config\Services\BookingConfigService as S;
use App\Support\Api\Concurrency;
use App\Support\Ids;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BookingConfigController
{
    public function __construct(private readonly S $booking) {}

    public function schedule(string $resourceId): JsonResponse
    {
        $s = $this->booking->schedule($resourceId);

        return Concurrency::json($s, 200, $s['rowVersion']);
    }

    public function setSchedule(Request $request, string $resourceId): JsonResponse
    {
        $d = $request->validate([
            'windows' => ['present', 'array', 'max:70'], 'windows.*.dayOfWeek' => ['required', 'integer', 'between:1,7'], 'windows.*.open' => ['required', 'string'], 'windows.*.close' => ['required', 'string'],
            'windows.*.validFrom' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'windows.*.validTo' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $s = $this->booking->setSchedule($resourceId, $d['windows'], Concurrency::ifMatch($request));

        return Concurrency::json($s, 200, $s['rowVersion']);
    }

    public function blackouts(Request $request, string $resourceId): JsonResponse
    {
        return response()->json(['items' => $this->booking->blackouts($resourceId, $request->query('from'), $request->query('to')), 'nextCursor' => null]);
    }

    public function createBlackout(Request $request): JsonResponse
    {
        $d = $request->validate(['facilityId' => ['required', 'uuid'], 'start' => ['required', 'date'], 'end' => ['required', 'date', 'after:start'], 'reason' => ['nullable', 'string', 'max:255']]);

        return response()->json($this->booking->createFacilityBlackout($d), 201);
    }

    public function deleteBlackout(string $blackoutId): Response
    {
        $this->booking->deleteBlackout($blackoutId);

        return response()->noContent();
    }

    public function rules(string $resourceId): JsonResponse
    {
        $r = $this->booking->rules($resourceId);

        return Concurrency::json($r, 200, $r['rowVersion']);
    }

    public function setRules(Request $request, string $resourceId): JsonResponse
    {
        $rules = [];
        foreach (S::RULES as $key => [, [$min, $max], $int]) {
            $rules[$key] = ['sometimes', 'nullable', $int ? 'integer' : 'numeric', "min:{$min}", "max:{$max}"];
        }
        $d = $request->validate($rules);
        $r = $this->booking->setRules($resourceId, $d, Concurrency::ifMatch($request));

        return Concurrency::json($r, 200, $r['rowVersion']);
    }
}
