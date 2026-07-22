<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\CurrentMunicipality;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request, CurrentMunicipality $currentMunicipality): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'E-mail ou senha inválidos.',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->forget('active_municipality_id');
        $municipalities = $request->user()->municipalities()->complete()->get();

        if ($municipalities->isEmpty()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'Esta conta não possui um município com cadastro completo.',
            ]);
        }

        if ($municipalities->count() === 1) {
            $currentMunicipality->activate($request, $municipalities->first());

            return redirect()->intended(route($request->user()->landingRouteName($municipalities->first()->id)));
        }

        return redirect()->route('municipalities.select');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
