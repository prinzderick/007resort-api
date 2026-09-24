<?php

namespace App\Domain\Identity\Auth;

/** Where an action happens, used to match role_assignment scope (org > site > facility subtree). */
final class Scope
{
    private function __construct(
        public readonly ?string $organizationId = null,
        public readonly ?string $siteId = null,
        public readonly ?string $facilityUnitId = null,
    ) {}

    public static function organization(string $id): self
    {
        return new self(organizationId: $id);
    }

    public static function site(string $id): self
    {
        return new self(siteId: $id);
    }

    public static function facility(string $id): self
    {
        return new self(facilityUnitId: $id);
    }
}
