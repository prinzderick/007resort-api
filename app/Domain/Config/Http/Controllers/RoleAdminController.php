<?php

namespace App\Domain\Config\Http\Controllers;

use App\Domain\Config\Services\RoleAdminService;
use App\Support\Api\Concurrency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RoleAdminController
{
    public function __construct(private readonly RoleAdminService $roles) {}

    public function matrix(string $roleId): JsonResponse
    {
        $m = $this->roles->matrix($this->roles->find($roleId));

        return Concurrency::json($m, 200, $m['role']['rowVersion']);
    }

    public function setPermissions(Request $request, string $roleId): JsonResponse
    {
        $d = $request->validate(['permissions' => ['present', 'array', 'max:500'], 'permissions.*.code' => ['required', 'string', 'max:96'], 'permissions.*.requiresApproval' => ['sometimes', 'boolean']]);
        $m = $this->roles->setPermissions($roleId, $d['permissions'], Concurrency::ifMatch($request));

        return Concurrency::json($m, 200, $m['role']['rowVersion']);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'min:2', 'max:120'], 'description' => ['sometimes', 'nullable', 'string', 'max:255'], 'permissions' => ['sometimes', 'array', 'max:500'], 'permissions.*' => ['string', 'max:96']]);
        $m = $this->roles->create($d);

        return Concurrency::json($m, 201, $m['role']['rowVersion']);
    }

    public function update(Request $request, string $roleId): JsonResponse
    {
        $d = $request->validate(['name' => ['sometimes', 'string', 'min:2', 'max:120'], 'description' => ['sometimes', 'nullable', 'string', 'max:255']]);
        $m = $this->roles->update($roleId, $d, Concurrency::ifMatch($request));

        return Concurrency::json($m, 200, $m['role']['rowVersion']);
    }

    public function destroy(string $roleId): Response
    {
        $this->roles->delete($roleId);

        return response()->noContent();
    }
}
