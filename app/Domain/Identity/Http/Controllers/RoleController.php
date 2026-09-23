<?php

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Identity\Models\Role;
use App\Support\Http\CursorPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleController
{
    /** GET /roles — roles with their permission bundles (`id` is the stable public UUID). */
    public function roles(Request $request): JsonResponse
    {
        $page = CursorPage::paginate(Role::query(), $request, 'code', 'asc', 50, 200, 'public_id');
        $bundles = DB::table('role_permission as rp')->join('permission as p', 'p.id', '=', 'rp.permission_id')
            ->whereIn('rp.role_id', $page->items->pluck('id')->all())->orderBy('p.code')->get(['rp.role_id', 'p.code', 'rp.requires_approval'])->groupBy('role_id');

        return response()->json($page->toArray(fn (Role $r) => [
            'id' => $r->public_id, 'code' => $r->code, 'name' => $r->name, 'description' => $r->description,
            'permissions' => ($bundles[$r->id] ?? collect())->pluck('code')->values()->all(),
            'approvalRequired' => ($bundles[$r->id] ?? collect())->where('requires_approval', 1)->pluck('code')->values()->all(),
        ]));
    }

    /** GET /permissions — the fixed permission catalog. */
    public function permissions(): JsonResponse
    {
        return response()->json([
            'items' => DB::table('permission')->orderBy('code')->get(['code', 'description'])->map(fn ($p) => ['code' => $p->code, 'description' => $p->description])->all(),
            'nextCursor' => null,
        ]);
    }
}
