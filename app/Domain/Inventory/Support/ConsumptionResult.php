<?php

namespace App\Domain\Inventory\Support;

final class ConsumptionResult
{
    /** @param list<array{movementId: string, itemId: string, locationId: string, quantityDelta: string, replayed: bool}> $movements */
    public function __construct(public readonly array $movements) {}

    public function appliedCount(): int
    {
        return count(array_filter($this->movements, fn ($m) => ! $m['replayed']));
    }

    public function replayedCount(): int
    {
        return count($this->movements) - $this->appliedCount();
    }
}
