<?php

namespace App\Http\Controllers;

use App\Models\MunicipalityInvitation;
use App\Models\User;
use App\Services\CurrentMunicipality;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class InvitationAcceptanceController extends Controller
{
    public function show(Request $request, string $token): View|RedirectResponse
    {
        $invitation = MunicipalityInvitation::findAvailableByToken($token)->load('municipality');
        $existingUser = User::query()->where('email', $invitation->email)->first();

        if ($existingUser && ! $request->user()) {
            return redirect()->guest(route('login'))
                ->with('status', 'Entre com o e-mail convidado para continuar.');
        }

        if ($existingUser && ! $request->user()->is($existingUser)) {
            abort(403);
        }

        if (! $existingUser && $request->user()) {
            abort(403);
        }

        return view('invitations.accept', [
            'invitation' => $invitation,
            'token' => $token,
            'needsRegistration' => $existingUser === null,
        ]);
    }

    public function accept(
        Request $request,
        string $token,
        CurrentMunicipality $currentMunicipality,
    ): RedirectResponse {
        $invitation = MunicipalityInvitation::findAvailableByToken($token);
        $existingUser = User::query()->where('email', $invitation->email)->first();
        $validated = [];

        if ($existingUser) {
            abort_unless($request->user()?->is($existingUser), 403);
        } else {
            abort_if($request->user(), 403);
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'confirmed', Password::min(8)],
            ]);
        }

        [$user, $municipality, $createdUser] = DB::transaction(function () use ($request, $invitation, $token, $validated): array {
            $lockedInvitation = MunicipalityInvitation::query()
                ->available()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->findOrFail($invitation->id);
            $user = User::query()
                ->where('email', $lockedInvitation->email)
                ->lockForUpdate()
                ->first();
            $createdUser = false;

            if ($user) {
                abort_unless($request->user()?->is($user), 403);
            } else {
                abort_if($request->user(), 403);
                $user = User::create([
                    'name' => trim($validated['name']),
                    'email' => $lockedInvitation->email,
                    'password' => $validated['password'],
                ]);
                $createdUser = true;
            }

            $lockedInvitation->municipality->users()->syncWithoutDetaching([
                $user->id => [
                    'role' => $lockedInvitation->role,
                    'legislative_name' => $lockedInvitation->legislative_name,
                    'legislative_party' => $lockedInvitation->legislative_party,
                    'legislative_term_start' => $lockedInvitation->legislative_term_start,
                    'legislative_term_end' => $lockedInvitation->legislative_term_end,
                ],
            ]);
            $lockedInvitation->update(['accepted_at' => now()]);

            return [$user, $lockedInvitation->municipality, $createdUser];
        });

        if ($createdUser) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        $currentMunicipality->activate($request, $municipality);

        return redirect()
            ->route($user->landingRouteName($municipality->id))
            ->with('status', "Acesso a {$municipality->name} ativado.");
    }
}
