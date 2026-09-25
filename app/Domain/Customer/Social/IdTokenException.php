<?php

namespace App\Domain\Customer\Social;

use RuntimeException;

/** A Google id token was rejected. `reason` is a stable machine value (never contains the token). */
final class IdTokenException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("id token rejected: {$reason}");
    }
}
