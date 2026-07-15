<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Municipality;
use App\Models\User;
use App\Rules\ValidCnpj;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(Request $request, FormSubmission $formSubmission): View
    {
        return view('auth.register', [
            'states' => Municipality::states(),
            'submissionToken' => $formSubmission->issue($request, 'register'),
        ]);
    }

    public function store(
        Request $request,
        FormSubmission $formSubmission,
        CurrentMunicipality $currentMunicipality,
    ): RedirectResponse {
        $request->merge([
            'state' => strtoupper(trim((string) $request->input('state'))),
            'cnpj' => preg_replace('/\D/', '', (string) $request->input('cnpj')),
            'ibge_code' => preg_replace('/\D/', '', (string) $request->input('ibge_code')),
        ]);

        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'municipality_name' => ['required', 'string', 'max:255'],
            'state' => ['required', Rule::in(array_keys(Municipality::states()))],
            'cnpj' => ['required', new ValidCnpj, 'unique:municipalities,cnpj'],
            'ibge_code' => ['required', 'digits:7', 'unique:municipalities,ibge_code'],
        ]);

        if (! $formSubmission->consume($request, 'register')) {
            return back()
                ->withInput($request->except(['password', 'password_confirmation']))
                ->withErrors(['_submission_token' => 'Este cadastro já foi enviado. Atualize a página antes de tentar novamente.']);
        }

        try {
            [$user, $municipality] = DB::transaction(function () use ($validated): array {
                $user = User::create([
                    'name' => trim($validated['name']),
                    'email' => strtolower($validated['email']),
                    'password' => $validated['password'],
                ]);

                $municipality = Municipality::create([
                    'name' => trim($validated['municipality_name']),
                    'state' => $validated['state'],
                    'cnpj' => $validated['cnpj'],
                    'ibge_code' => $validated['ibge_code'],
                ]);

                $municipality->users()->attach($user, ['role' => 'manager']);

                return [$user, $municipality];
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            return back()
                ->withInput($request->except(['password', 'password_confirmation']))
                ->withErrors(['email' => 'E-mail, CNPJ ou código IBGE já cadastrado.']);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $currentMunicipality->activate($request, $municipality);

        return redirect()->route('dashboard');
    }
}
