<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Municipality;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'municipality_name' => ['required', 'string', 'max:255'],
            'state' => ['required', 'string', 'size:2'],
            'cnpj' => ['nullable', 'string', 'max:18'],
            'ibge_code' => ['nullable', 'digits:7', 'unique:municipalities,ibge_code'],
        ]);

        $cnpj = preg_replace('/\D/', '', (string) ($validated['cnpj'] ?? '')) ?: null;

        if ($cnpj !== null && (strlen($cnpj) !== 14 || Municipality::where('cnpj', $cnpj)->exists())) {
            return back()
                ->withInput($request->except(['password', 'password_confirmation']))
                ->withErrors(['cnpj' => 'Informe um CNPJ único com 14 dígitos.']);
        }

        $user = DB::transaction(function () use ($validated, $cnpj): User {
            $user = User::create([
                'name' => trim($validated['name']),
                'email' => strtolower($validated['email']),
                'password' => $validated['password'],
            ]);

            $municipality = Municipality::create([
                'name' => trim($validated['municipality_name']),
                'state' => strtoupper($validated['state']),
                'cnpj' => $cnpj,
                'ibge_code' => ($validated['ibge_code'] ?? null) ?: null,
            ]);

            $municipality->users()->attach($user, ['role' => 'manager']);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
