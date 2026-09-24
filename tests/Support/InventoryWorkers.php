<?php

namespace Tests\Support;

use App\Domain\Inventory\Contracts\InventoryConsumption;
use App\Domain\Inventory\Contracts\RentalGateway;
use App\Domain\Inventory\Services\StockDocuments;
use App\Domain\Inventory\Support\ConsumptionLine;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Bodies executed in child processes (own app, own MySQL connection) by Tests\Support\Concurrent. */
final class InventoryWorkers
{
    /** Each worker "sells" $qty of the item with its OWN order line — like N terminals selling the last unit. */
    public function sell(int $index, string $facilityId, string $itemId, string $qty): array
    {
        return $this->guard(function () use ($facilityId, $itemId, $qty) {
            DB::transaction(fn () => app(InventoryConsumption::class)->consume($facilityId, [new ConsumptionLine($itemId, $qty, Ids::uuid7())], 'order', Ids::uuid7()));
        });
    }

    /** All workers replay the SAME order line (event delivered N times / client retry storm). */
    public function sellSameLine(int $index, string $facilityId, string $itemId, string $qty, string $orderId, string $lineId): array
    {
        return $this->guard(function () use ($facilityId, $itemId, $qty, $orderId, $lineId) {
            DB::transaction(fn () => app(InventoryConsumption::class)->consume($facilityId, [new ConsumptionLine($itemId, $qty, $lineId)], 'order', $orderId));
        });
    }

    /** POST /inventory/transfers through the full HTTP stack (auth, permission, idempotency middleware). */
    public function transferHttp(int $index, string $token, string $from, string $to, array $lines, ?string $key = null): array
    {
        $req = Request::create('/api/v1/inventory/transfers', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_IDEMPOTENCY_KEY' => $key ?? 'xfer-'.$index.'-'.Ids::uuid7(),
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['fromLocationId' => $from, 'toLocationId' => $to, 'lines' => $lines]));
        $res = app(Kernel::class)->handle($req);
        $body = json_decode($res->getContent(), true);

        return ['status' => $res->getStatusCode(), 'code' => $body['code'] ?? null, 'id' => $body['id'] ?? null];
    }

    public function transferService(int $index, string $from, string $to, array $lines): array
    {
        return $this->guard(function () use ($from, $to, $lines) {
            app(StockDocuments::class)->transfer(['fromLocationId' => $from, 'toLocationId' => $to, 'lines' => $lines], null);
        });
    }

    public function issueAsset(int $index, string $tag): array
    {
        return $this->guard(function () use ($tag) {
            DB::transaction(fn () => app(RentalGateway::class)->issueAsset($tag, 'entitlement_item', Ids::uuid7()));
        });
    }

    private function guard(callable $fn): array
    {
        try {
            $fn();

            return ['ok' => true, 'code' => null];
        } catch (ApiProblem $e) {
            return ['ok' => false, 'code' => $e->problemCode];
        } catch (Throwable $e) {
            return ['ok' => false, 'code' => 'EXCEPTION', 'message' => substr(preg_replace('/[^\x20-\x7E]/', '?', get_class($e).': '.$e->getMessage()), 0, 400)];
        }
    }
}
