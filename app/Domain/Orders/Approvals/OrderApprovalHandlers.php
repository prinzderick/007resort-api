<?php

namespace App\Domain\Orders\Approvals;

use App\Domain\Orders\Services\OrderService;
use App\Support\Http\ApiProblem;
use App\Support\Ids;

/** Handlers for `order.void` and `order.adjust` approvals. */
final class OrderApprovalHandlers
{
    public static function void(OrderService $orders): ApprovalHandler
    {
        return new class($orders) implements ApprovalHandler
        {
            public function __construct(private readonly OrderService $orders) {}

            public function approved(object $approval, array $payload, string $approverStaffId): void
            {
                $order = $this->orders->restoreFromApproval($payload['orderId']);
                if (! in_array($order->status, OrderService::NOT_PAID_VOIDABLE, true) || bccomp($order->amount_paid, '0', 4) > 0) {
                    throw ApiProblem::conflict('order_state_invalid', "The order can no longer be voided (it is {$order->status} / has payments).");
                }
                $this->orders->applyVoid($order, $payload['reason'], Ids::fromBinary($approval->id), $approverStaffId);
            }

            public function discarded(object $approval, array $payload, string $status): void
            {
                $this->orders->restoreFromApproval($payload['orderId']);
            }
        };
    }

    public static function adjust(OrderService $orders): ApprovalHandler
    {
        return new class($orders) implements ApprovalHandler
        {
            public function __construct(private readonly OrderService $orders) {}

            public function approved(object $approval, array $payload, string $approverStaffId): void
            {
                $this->orders->applyAdjustment($payload['orderId'], $payload['adjustmentId'], Ids::fromBinary($approval->id), $approverStaffId);
            }

            public function discarded(object $approval, array $payload, string $status): void
            {
                $this->orders->rejectAdjustment($payload['adjustmentId']);
            }
        };
    }
}
