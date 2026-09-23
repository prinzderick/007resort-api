<?php

namespace App\Providers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Module auto-discovery (ADR-0002 modular monolith). Every directory under app/Domain/<Module>/ is a module:
 *
 *   <Module>ServiceProvider.php   registered automatically if present (bindings, policies, listeners, commands)
 *   routes.php                    loaded under /api/v1 with the `api` middleware group
 *   Migrations/                   loaded automatically by `php artisan migrate`
 *   config.php                    merged as config('<module lower-case>.*')
 *
 * NO shared file needs editing to add a module. See docs/MODULES.md.
 */
class ModuleServiceProvider extends ServiceProvider
{
    /** @return list<string> module directory names, e.g. ['Audit', 'Identity', ...] */
    public static function modules(): array
    {
        $base = app_path('Domain');
        if (! is_dir($base)) {
            return [];
        }
        $names = array_map('basename', File::directories($base));
        sort($names);

        return $names;
    }

    public function register(): void
    {
        foreach (self::modules() as $module) {
            $dir = app_path("Domain/{$module}");
            if (is_file($config = "{$dir}/config.php")) {
                $key = strtolower($module);
                $this->app['config']->set($key, array_replace_recursive(require $config, (array) $this->app['config']->get($key, [])));
            }
            $provider = "App\\Domain\\{$module}\\{$module}ServiceProvider";
            if (class_exists($provider)) {
                $this->app->register($provider);
            }
        }
    }

    public function boot(): void
    {
        foreach (self::modules() as $module) {
            $dir = app_path("Domain/{$module}");
            if (is_dir("{$dir}/Migrations")) {
                $this->loadMigrationsFrom("{$dir}/Migrations");
            }
        }

        if ($this->app->routesAreCached()) {
            return;
        }
        Route::middleware('api')->prefix('api/v1')->group(function () {
            foreach (self::modules() as $module) {
                if (is_file($routes = app_path("Domain/{$module}/routes.php"))) {
                    require $routes;
                }
            }
        });
    }
}
