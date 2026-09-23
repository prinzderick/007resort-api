<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Contracts\CloudBookingAuthority;
use App\Domain\Booking\Contracts\CloudUnreachableException;
use App\Domain\Booking\Support\HoldCommand;

final class NullCloudBookingAuthority implements CloudBookingAuthority
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function hold(HoldCommand $command): array
    {
        throw new CloudUnreachableException('No Cloud booking authority is bound (Sync module not installed).');
    }
}
