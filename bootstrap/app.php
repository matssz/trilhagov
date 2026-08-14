<?php

use App\Http\Middleware\EnsureActiveMunicipality;
use App\Http\Middleware\EnsureMunicipalityModuleEnabled;
use App\Http\Middleware\EnsureMunicipalityRole;
use App\Http\Middleware\PreventAuthenticatedResponseCaching;
use App\Http\Middleware\SecurityHeaders;
use App\Services\OccurrenceCenterService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            SecurityHeaders::class,
            PreventAuthenticatedResponseCaching::class,
        ]);
        $middleware->alias([
            'municipality' => EnsureActiveMunicipality::class,
            'municipality.module' => EnsureMunicipalityModuleEnabled::class,
            'municipality.role' => EnsureMunicipalityRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->report(function (Throwable $exception) {
            app(OccurrenceCenterService::class)->recordException($exception, request());
        });
    })->create();
