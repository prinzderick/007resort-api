<?php

namespace App\Domain\Payments\Http\Controllers;

use App\Domain\Payments\Provider\Terminal\TerminalCharge;
use App\Domain\Payments\Services\CollectionService;
use App\Domain\Payments\Services\TerminalAdapterRegistry;
use App\Domain\Payments\Support\Fmt;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** POST /orders/{id}/collections, /payments/{id}/confirm|reject|cancel, terminal callbacks. */
class CollectionController
{
    public function __construct(private readonly CollectionService $collections, private readonly TerminalAdapterRegistry $terminals) {}

    public function collect(Request $request, string $orderId): JsonResponse
    {
        $in = $request->validate([
            'id' => ['nullable', function ($a, $v, $fail) {
                Fmt::isUuid7($v) || $fail('The id must be a UUIDv7.');
            }],
            'tenderType' => ['required', 'in:CASH,CARD_TERMINAL,TRANSFER,PAY_LINK'],
            'amount' => ['required', self::positiveMoney()],
            'tendered' => ['nullable', self::positiveMoney()],
            'terminalId' => ['nullable', 'uuid'],
            'approvalCode' => ['nullable', 'string', 'max:64'],
            'slipReference' => ['nullable', 'string', 'max:64'],
            'last4' => ['nullable', 'digits:4'],
            'bankReference' => ['nullable', 'string', 'max:128'],
            'channel' => ['nullable', 'in:MANUAL,PAYSTACK'],
            'customerEmail' => ['nullable', 'email', 'max:190'],
            'note' => ['nullable', 'string', 'max:255'],
            'clientCreatedAt' => ['nullable', 'date'],
        ]);
        if (($in['channel'] ?? null) !== null && $in['tenderType'] !== 'TRANSFER') {
            throw ApiProblem::unprocessable('validation_failed', 'channel is only valid for TRANSFER.', ['channel' => ['Only for TRANSFER.']]);
        }
        $r = $this->collections->collect(Ids::isUuid($orderId) ? Ids::normalize($orderId) : throw ApiProblem::notFound(), $in, $this->staff());

        return response()->json($r['body'], $r['replayed'] ? 200 : 201, $r['replayed'] ? ['Idempotent-Replayed' => 'true'] : []);
    }

    public function confirm(Request $request, string $paymentId): JsonResponse
    {
        $in = $request->validate(['matchedReference' => ['nullable', 'string', 'max:128'], 'note' => ['nullable', 'string', 'max:255']]);
        $r = $this->collections->confirm($paymentId, $in, $this->staff());

        return response()->json($r['body'], 200, $r['replayed'] ? ['Idempotent-Replayed' => 'true'] : []);
    }

    public function reject(Request $request, string $paymentId): JsonResponse
    {
        $in = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $r = $this->collections->reject($paymentId, $in['reason'], $this->staff());

        return response()->json($r['body'], 200, $r['replayed'] ? ['Idempotent-Replayed' => 'true'] : []);
    }

    public function cancel(Request $request, string $paymentId): JsonResponse
    {
        $in = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return response()->json($this->collections->cancel($paymentId, $in['reason'] ?? null, $this->staff()));
    }

    /** POST /payments/terminal-callbacks/{provider} (signature-authenticated by the adapter; no bearer). */
    public function terminalCallback(Request $request, string $provider): JsonResponse
    {
        $adapter = $this->terminals->for(strtoupper($provider));
        $charge = $adapter->parseCallback($request->getContent(), $request->header('X-Signature'));
        if ($charge === null || $charge->reference === null) {
            throw ApiProblem::badRequest('validation_failed', 'This provider sends no (verifiable) callbacks.');
        }
        $done = $charge->status === TerminalCharge::CONFIRMED && $this->collections->confirmFromTerminal($charge->reference);

        return response()->json(['received' => true, 'duplicate' => ! $done]);
    }

    private static function positiveMoney(): \Closure
    {
        return function (string $attr, mixed $value, \Closure $fail): void {
            if (! Money::isValid($value)) {
                $fail('The :attribute must be a decimal string with at most 4 decimal places.');
            } elseif (bccomp((string) $value, '0', 4) <= 0) {
                $fail('The :attribute must be greater than zero.');
            }
        };
    }

    private function staff(): string
    {
        return RequestContext::staffId() ?? throw ApiProblem::unauthenticated();
    }
}
