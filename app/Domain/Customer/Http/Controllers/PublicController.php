<?php

namespace App\Domain\Customer\Http\Controllers;

use App\Domain\Customer\Services\PublicSiteService;
use App\Domain\Customer\Services\TicketOrderService;
use App\Domain\Customer\Support\Actor;
use App\Domain\Guest\Services\GuestCheckout;
use App\Support\Http\ApiProblem;
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
            'guest' => ['nullable', 'array'],
        ]);
        $guests = app(GuestCheckout::class);
        if ($guests->isGuestRequest()) { // website service token: checkout without an account (docs/GUEST_CHECKOUT.md)
            $contact = $guests->contact($d['guest'] ?? null, $request);
            $order = $orders->create($d, null, $contact);
            $order['guestAccess'] = $guests->open($contact, 'TICKETS', $order['id']);

            return response()->json($order, 201);
        }
        if (isset($d['guest'])) {
            throw ApiProblem::unprocessable('validation_failed', 'guest is only for checkout without an account.', ['guest' => ['not allowed here']]);
        }

        return response()->json($orders->create($d, Actor::requireCustomer()), 201);
    }
}
