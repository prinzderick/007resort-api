<?php

namespace App\Domain\Ticketing\Services;

use App\Domain\Ticketing\Contracts\OrderLineSource;
use App\Support\Http\ApiProblem;

final class NullOrderLineSource implements OrderLineSource
{
    public function forOrder(string $orderId): ?array
    {
        throw ApiProblem::unprocessable('validation_failed', 'Issuing by orderId requires the Orders module.', ['orderId' => ['orders module not installed']]);
    }
}
