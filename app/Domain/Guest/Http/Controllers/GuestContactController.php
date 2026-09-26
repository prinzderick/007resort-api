<?php

namespace App\Domain\Guest\Http\Controllers;

use App\Domain\Guest\Services\GuestErasure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuestContactController
{
    /** POST /guest-contacts/erasure (staff, config.manage): anonymise guest contact data on request. */
    public function erase(Request $request, GuestErasure $erasure): JsonResponse
    {
        $d = $request->validate(['email' => ['nullable', 'string', 'max:254'], 'phone' => ['nullable', 'string', 'max:40'], 'reference' => ['nullable', 'string', 'max:32']]);

        return response()->json(['anonymised' => $erasure->erase($d)]);
    }
}
