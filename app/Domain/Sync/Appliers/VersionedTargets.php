<?php

namespace App\Domain\Sync\Appliers;

use InvalidArgumentException;

/**
 * domain name -> VersionedTarget. `ConfigurationUpdated` / `StaffRosterUpdated` payloads name their `domain`;
 * modules register the tables they own from their service provider:
 *
 *   $this->callAfterResolving(VersionedTargets::class, fn (VersionedTargets $t) => $t->register('menuItem',
 *       new VersionedTarget('menu_item', 'CONFIGURATION', ['name' => ['name', 'string'], 'price' => ['price', 'decimal']])));
 */
final class VersionedTargets
{
    /** @var array<string, VersionedTarget> */
    private array $targets = [];

    public function register(string $domain, VersionedTarget $target): void
    {
        if (isset($this->targets[$domain])) {
            throw new InvalidArgumentException("A versioned sync target is already registered for domain '{$domain}'.");
        }
        $this->targets[$domain] = $target;
    }

    public function get(string $domain): ?VersionedTarget
    {
        return $this->targets[$domain] ?? null;
    }
}
