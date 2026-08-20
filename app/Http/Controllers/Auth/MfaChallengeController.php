<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CurrentMunicipality;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MfaChallengeController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function show(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('mfa_user_id')) {
            return redirect()->route('login');
        }

        return view('auth.mfa-challenge');
    }

    public function verify(Request $request, CurrentMunicipality $currentMunicipality): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'digits:6'],
        ], [
            'code.required' => 'Informe o codigo de verificacao.',
            'code.digits' => 'O codigo deve ter 6 digitos.',
        ]);

        $userId = (int) $request->session()->get('mfa_user_id');
        $throttleKey = 'mfa|'.$userId.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'code' => "Muitas tentativas. Tente novamente em {$seconds} segundos.",
            ]);
        }

        $user = User::query()->find($userId);

        if (! $user || ! $user->mfa_code_hash || ! $user->mfa_code_expires_at || $user->mfa_code_expires_at->isPast()) {
            $request->session()->forget(['mfa_user_id', 'mfa_remember']);

            throw ValidationException::withMessages([
                'code' => 'Codigo expirado. Entre novamente para gerar um novo codigo.',
            ]);
        }

        if (! Hash::check($validated['code'], $user->mfa_code_hash)) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'code' => 'Codigo invalido. Confira os 6 digitos e tente novamente.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        $remember = (bool) $request->session()->pull('mfa_remember', false);
        $request->session()->forget('mfa_user_id');
        $user->forceFill([
            'mfa_code_hash' => null,
            'mfa_code_expires_at' => null,
        ])->save();

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return app(AuthenticatedSessionController::class)->redirectAfterAuthenticatedLogin($request, $currentMunicipality);
    }
}
