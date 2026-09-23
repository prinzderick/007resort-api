<?php

use App\Domain\Identity\Http\Middleware\RequirePermission;
use App\Domain\Identity\Http\Middleware\RequireStepUp;
use App\Support\Http\ApiProblem;
use App\Support\Http\OpenApiController;
use App\Support\Http\ProblemRenderer;
use App\Support\Idempotency\Idempotent;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api/v1',
        health: '/up',
        then: function () {
            // Dev-only API docs (404 in production).
            Route::get('/api/documentation', [OpenApiController::class, 'ui']);
            Route::get('/api/openapi.yaml', [OpenApiController::class, 'spec']);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => null); // API only: never redirect to a login page

        // Route middleware aliases usable from any module's routes.php.
        // (A module may also register its own aliases from its service provider.)
        $middleware->alias([
            'permission' => RequirePermission::class,   // permission:order.void.approve[,facility=facilityId]
            'idempotent' => Idempotent::class,          // idempotent[:scope]  (put after auth)
            'stepup' => RequireStepUp::class,           // recent password/PIN re-entry
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([ApiProblem::class]);
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);
        // RFC 7807 problem+json with stable `code` for every failure (see ProblemRenderer).
        $exceptions->render(fn (Throwable $e, Request $request) => ProblemRenderer::render($e, $request));
    })->create();
