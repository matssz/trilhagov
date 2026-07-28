<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMunicipalityModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $municipality = $request->attributes->get('active_municipality');

        abort_unless($municipality?->moduleEnabled($module), 404);

        return $next($request);
    }
}
