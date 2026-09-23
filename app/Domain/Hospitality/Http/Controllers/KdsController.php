<?php

namespace App\Domain\Hospitality\Http\Controllers;

use App\Domain\Hospitality\Services\KdsService;
use App\Domain\Hospitality\Services\TicketPresenter;
use App\Support\Api\Concurrency;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KdsController
{
    public function __construct(private readonly KdsService $kds, private readonly TicketPresenter $tickets) {}

    public function stations(Request $request): JsonResponse
    {
        if (($f = $request->query('facilityId')) !== null && ! Ids::isUuid($f)) {
            throw ApiProblem::unprocessable('validation_failed', 'facilityId must be a UUID.');
        }

        return response()->json($this->kds->stations($request));
    }

    public function board(Request $request, string $stationId): JsonResponse
    {
        return response()->json($this->kds->board($request, $this->id($stationId)));
    }

    public function show(string $ticketId): JsonResponse
    {
        $t = $this->tickets->ticket($this->kds->get($this->id($ticketId)));

        return Concurrency::json($t, 200, $t['rowVersion']);
    }

    public function transition(Request $request, string $ticketId): JsonResponse
    {
        $d = $request->validate(['to' => ['required', 'string', 'in:ACCEPTED,IN_PROGRESS,READY,DISPENSED'], 'note' => ['nullable', 'string', 'max:255']]);
        $t = $this->kds->transition($this->id($ticketId), $d['to'], Concurrency::ifMatch($request), $d['note'] ?? null);

        return Concurrency::json($t, 200, $t['rowVersion']);
    }

    private function id(string $id): string
    {
        return Ids::isUuid($id) ? Ids::normalize($id) : throw ApiProblem::notFound();
    }
}
