<?php

namespace App\Http\Controllers;

use App\Models\MunicipalityInvitation;
use App\Models\User;
use App\Notifications\MunicipalityInvitationNotification;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class MunicipalUserController extends Controller
{
    public function index(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
    ): View {
        $municipality = $currentMunicipality->get($request);

        return view('users.index', [
            'municipality' => $municipality,
            'members' => $municipality->users()->orderBy('name')->get(),
            'invitations' => MunicipalityInvitation::query()
                ->where('municipality_id', $municipality->id)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->latest()
                ->get(),
            'roles' => User::municipalityRoles(),
            'invitableRoles' => array_intersect_key(
                User::municipalityRoles(),
                array_flip([User::ROLE_EDITOR, User::ROLE_VIEWER, User::ROLE_AUDITOR]),
            ),
            'submissionToken' => $formSubmission->issue($request, 'municipality-invitation-create'),
        ]);
    }

    public function invite(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
    ): RedirectResponse {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in([User::ROLE_EDITOR, User::ROLE_VIEWER, User::ROLE_AUDITOR])],
        ]);

        if (! $formSubmission->consume($request, 'municipality-invitation-create')) {
            return back()->with('warning', 'Este convite já foi processado.');
        }

        $municipality = $currentMunicipality->get($request);

        if ($municipality->users()->where('users.email', $validated['email'])->exists()) {
            return back()->withInput()->withErrors([
                'email' => 'Este e-mail já possui acesso ao município.',
            ]);
        }

        $token = Str::random(64);
        $invitation = DB::transaction(function () use ($request, $municipality, $validated, $token): MunicipalityInvitation {
            MunicipalityInvitation::query()
                ->where('municipality_id', $municipality->id)
                ->where('email', $validated['email'])
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            return MunicipalityInvitation::create([
                'municipality_id' => $municipality->id,
                'invited_by' => $request->user()->id,
                'email' => $validated['email'],
                'role' => $validated['role'],
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(7),
            ]);
        });

        $acceptUrl = route('invitations.show', $token);

        try {
            Notification::route('mail', $invitation->email)
                ->notify(new MunicipalityInvitationNotification($invitation->load('municipality'), $acceptUrl));
        } catch (Throwable $exception) {
            report($exception);
        }

        return redirect()
            ->route('users.index')
            ->with('invitation_link', $acceptUrl);
    }

    public function updateRole(
        Request $request,
        int $user,
        CurrentMunicipality $currentMunicipality,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $validated = $request->validate([
            'role' => ['required', Rule::in(array_keys(User::municipalityRoles()))],
        ]);
        $municipality = $currentMunicipality->get($request);
        $member = $municipality->users()->findOrFail($user);

        if ($member->is($request->user())) {
            return back()->withErrors(['role' => 'Seu próprio perfil não pode ser alterado por esta tela.']);
        }

        if (
            $member->pivot->role === User::ROLE_MANAGER
            && $validated['role'] !== User::ROLE_MANAGER
            && $municipality->users()->wherePivot('role', User::ROLE_MANAGER)->count() <= 1
        ) {
            return back()->withErrors(['role' => 'O município precisa manter pelo menos um gestor.']);
        }

        $oldRole = $member->pivot->role;

        if ($oldRole === $validated['role']) {
            return back()->with('warning', 'O usuário já possui este perfil de acesso.');
        }

        DB::transaction(function () use ($request, $municipality, $member, $validated, $oldRole, $auditTrail): void {
            $municipality->users()->updateExistingPivot($member->id, ['role' => $validated['role']]);
            $auditTrail->recordRoleUpdate($request, $municipality, $member, $oldRole, $validated['role']);
        });

        return back()->with('status', "Perfil de {$member->name} atualizado.");
    }

    public function revokeInvitation(
        Request $request,
        int $invitation,
        CurrentMunicipality $currentMunicipality,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $pendingInvitation = MunicipalityInvitation::query()
            ->where('municipality_id', $municipality->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->findOrFail($invitation);
        $pendingInvitation->update(['revoked_at' => now()]);

        return back()->with('status', 'Convite revogado.');
    }
}
