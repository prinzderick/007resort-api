<?php

namespace App\Domain\Config;

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
        //
    }
}
