<?php

namespace App\Domain\Config;

use App\Domain\Config\Sync\ConfigSyncTargets;
use App\Domain\Sync\Appliers\VersionedTargets;
use Illuminate\Support\ServiceProvider;

/**
 * Configuration management (admin façade). Owns the write paths that let an administrator run the property from the UI
 * (facilities, capabilities, typed operating rules, operating points/stations/tables, device assignment, catalogue admin extras,
 * settings, roles matrix, setup status, search). Runtime modules keep reading the same tables through their own services.
 * See docs/CONFIG_ADMIN_API.md.
 */
class ConfigServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Receive side of every ConfigurationUpdated domain this module emits (version-checked, see ConfigSyncTargets).
        $this->callAfterResolving(VersionedTargets::class, fn (VersionedTargets $t) => ConfigSyncTargets::register($t));
    }
}
