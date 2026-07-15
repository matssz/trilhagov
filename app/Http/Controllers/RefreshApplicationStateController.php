<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class RefreshApplicationStateController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();
        $previousMunicipalityId = (int) $request->session()->get('active_municipality_id');

        $request->session()->forget([
            'active_municipality_id',
            'form_submission_tokens',
            '_old_input',
            'errors',
            'url.intended',
        ]);
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();

        $municipalities = $user->municipalities()->complete()->get();

        if ($municipalities->isEmpty()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $this->freshResponse(
                redirect()
                    ->route('login')
                    ->withErrors(['email' => 'Sua sessão foi atualizada, mas a conta não possui município ativo.']),
            );
        }

        $activeMunicipality = $municipalities->firstWhere('id', $previousMunicipalityId);

        if (! $activeMunicipality && $municipalities->count() === 1) {
            $activeMunicipality = $municipalities->first();
        }

        if ($activeMunicipality) {
            $request->session()->put('active_municipality_id', $activeMunicipality->id);
        }

        Log::info('Estado temporário da aplicação atualizado pelo usuário.', [
            'user_id' => $user->id,
            'municipality_id' => $activeMunicipality?->id,
            'ip_address' => $request->ip(),
        ]);

        $response = $activeMunicipality
            ? redirect()->route('dashboard')
            : redirect()->route('municipalities.select');

        return $this->freshResponse(
            $response->with('status', 'Sistema atualizado. Seu login e seus dados foram preservados.'),
        );
    }

    private function freshResponse(RedirectResponse $response): RedirectResponse
    {
        $response->headers->set('Clear-Site-Data', '"cache"');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->set('X-TrilhaGov-Refresh', '1');

        return $response;
    }
}
