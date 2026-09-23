<?php

namespace App\Domain\Customer\Http\Controllers;

use App\Domain\Customer\Services\PublicSiteService;
use App\Domain\Customer\Services\TicketOrderService;
use App\Domain\Customer\Support\Actor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PublicController
{
    public function site(PublicSiteService $site): JsonResponse
    {
        $body = Cache::remember('customer.public_site', 15, fn () => $site->build()); // 15 s: cheap for the website, still fresh for the heartbeat gate

        return response()->json($body)->header('Cache-Control', 'public, max-age=15');
    }

    public function ticketOrder(Request $request, TicketOrderService $orders): JsonResponse
    {
        $d = $request->validate([
            'facilityId' => ['required', 'uuid'],
            'visitDate' => ['required', 'date_format:Y-m-d'],
            'lines' => ['required', 'array', 'min:1', 'max:10'],
            'lines.*.productId' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:'.config('customer.tickets.max_per_order')],
            'customer' => ['nullable', 'array'], // accepted for contract compatibility; the owner is ALWAYS the signed-in customer
        ]);

        return response()->json($orders->create($d, Actor::requireCustomer()), 201);
    }
}
