<?php

namespace App\Domain\Identity\Auth;

/** A permission held by a staff member through one role assignment. */
final class Grant
{
    public function __construct(
        public readonly string $permission,
        public readonly bool $requiresApproval,
        public readonly string $scopeLevel,
        public readonly ?string $organizationId,
        public readonly ?string $siteId,
        public readonly ?string $facilityUnitId,
        public readonly string $assignmentId,
    ) {}
}
