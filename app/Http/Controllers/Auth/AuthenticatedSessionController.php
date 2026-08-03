<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Notifications\MfaCodeNotification;
use App\Services\CurrentMunicipality;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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

        if ($request->user()->mfa_enabled) {
            $user = $request->user();
            $code = app()->environment('testing') ? '123456' : (string) random_int(100000, 999999);
            $user->forceFill([
                'mfa_code_hash' => Hash::make($code),
                'mfa_code_expires_at' => now()->addMinutes(10),
            ])->save();
            $user->notify(new MfaCodeNotification($code));
            $request->session()->put('mfa_user_id', $user->id);
            $request->session()->put('mfa_remember', $request->boolean('remember'));
            Auth::logout();

            return redirect()
                ->route('mfa.challenge')
                ->with('status', app()->environment('testing') ? 'Codigo de verificacao gerado: 123456' : 'Codigo enviado para o e-mail cadastrado.');
        }

        return $this->redirectAfterAuthenticatedLogin($request, $currentMunicipality);
    }

    public function redirectAfterAuthenticatedLogin(Request $request, CurrentMunicipality $currentMunicipality): RedirectResponse
    {
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
