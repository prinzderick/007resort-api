<?php

namespace App\Domain\Sync\Support;

use RuntimeException;

/** @internal control flow: unwinds the applier savepoint when an event is not applied. */
final class NotApplied extends RuntimeException
{
    public function __construct(public readonly ApplyResult $result)
    {
        parent::__construct('not applied');
    }
}
