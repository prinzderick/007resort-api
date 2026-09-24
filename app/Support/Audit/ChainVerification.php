<?php

namespace App\Support\Audit;

final class ChainVerification
{
    public function __construct(
        public readonly bool $valid,
        public readonly int $rowsChecked,
        public readonly ?int $brokenAtSeq,
        public readonly ?string $reason,
        public readonly string $tailHash,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'rowsChecked' => $this->rowsChecked,
            'brokenAtSeq' => $this->brokenAtSeq,
            'reason' => $this->reason,
            'tailHash' => $this->tailHash,
        ];
    }
}
