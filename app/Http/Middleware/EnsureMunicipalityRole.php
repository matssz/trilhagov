<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMunicipalityRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $municipalityId = (int) $request->session()->get('active_municipality_id');
        $role = $request->user()->roleForMunicipality($municipalityId);

        abort_unless(in_array($role, $roles, true), 403);

        return $next($request);
    }
}
