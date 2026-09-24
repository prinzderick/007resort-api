<?php

namespace Tests\Support;

use App\Domain\Sync\Contracts\EntityOrdered;

class OrderedProbeApplier extends ProbeApplier implements EntityOrdered
{
    public function firstEntityVersion(): int
    {
        return 1;
    }
}
