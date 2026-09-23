<?php

namespace App\Domain\Customer\Http\Controllers;

use App\Domain\Customer\Services\ServiceTokenService;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Staff (`config.manage`) administration of the website's read-only credential. The plaintext token is returned exactly once. */
class ServiceTokenController
{
    public function __construct(private readonly ServiceTokenService $tokens) {}

    public function index(): array
    {
        return ['items' => $this->tokens->list(), 'nextCursor' => null];
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:80']]);

        return response()->json($this->tokens->create($d['name']), 201);
    }

    public function rotate(Request $request, string $id): JsonResponse
    {
        return response()->json($this->tokens->rotate($this->id($id), $request->boolean('immediate')), 201);
    }

    public function revoke(string $id): JsonResponse
    {
        $this->tokens->revoke($this->id($id));

        return response()->json(['status' => 'revoked']);
    }

    private function id(string $id): string
    {
        return Ids::isUuid($id) ? strtolower($id) : throw ApiProblem::notFound();
    }
}
