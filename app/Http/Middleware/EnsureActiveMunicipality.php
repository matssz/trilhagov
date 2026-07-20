<?php

namespace App\Http\Middleware;

use App\Services\CurrentMunicipality;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveMunicipality
{
    public function __construct(private readonly CurrentMunicipality $currentMunicipality) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $municipalities = $request->user()->municipalities()->complete()->get();

        if ($municipalities->isEmpty()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Esta conta não possui um município com cadastro completo.']);
        }

        $activeId = (int) $request->session()->get('active_municipality_id');

        if ($municipalities->contains('id', $activeId)) {
            $request->attributes->set('active_municipality', $municipalities->firstWhere('id', $activeId));

            return $next($request);
        }

        if ($municipalities->count() === 1) {
            $municipality = $municipalities->first();
            $this->currentMunicipality->activate($request, $municipality);
            $request->attributes->set('active_municipality', $municipality);

            return $next($request);
        }

        return redirect()->route('municipalities.select');
    }
}
