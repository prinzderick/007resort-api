<?php

namespace App\Domain\Payments\Http\Controllers;

use App\Domain\Payments\Services\PaystackService;
use App\Support\Http\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController
{
    public function __construct(private readonly PaystackService $paystack) {}

    /** POST /payments/webhooks/{provider} - no bearer auth; the provider signature IS the authentication. */
    public function receive(Request $request, string $provider): JsonResponse
    {
        if ($provider !== 'paystack') {
            throw ApiProblem::notFound('not_found', 'Unknown payment provider.');
        }
        $ack = $this->paystack->handleWebhook($request->getContent(), $request->header('X-Paystack-Signature'), $request->ip());

        return response()->json($ack);
    }
}
