<?php

namespace App\Domain\Ticketing\Services;

use App\Domain\Ticketing\Contracts\RentalStockHook;

final class NullRentalStockHook implements RentalStockHook
{
    public function rentalOut(array $context): void {}

    public function rentalIn(array $context): void {}
}
