<?php

namespace App\Domain\Attendance\Support;

use Carbon\CarbonImmutable;

final class ParsedPunch
{
    public function __construct(
        public readonly string $terminalUserId,
        public readonly CarbonImmutable $punchedAt, // UTC
        public readonly ?string $verifyMode = null,
        public readonly string $direction = 'UNKNOWN', // IN | OUT | UNKNOWN
        public readonly ?string $rawId = null,
    ) {}
}
