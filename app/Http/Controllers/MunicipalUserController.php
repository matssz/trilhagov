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
                array_flip([
                    User::ROLE_EDITOR, User::ROLE_VIEWER, User::ROLE_AUDITOR,
                    User::ROLE_COUNCILOR, User::ROLE_LEGISLATIVE_REVIEWER,
                ]),
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
            'role' => ['required', Rule::in([
                User::ROLE_EDITOR, User::ROLE_VIEWER, User::ROLE_AUDITOR,
                User::ROLE_COUNCILOR, User::ROLE_LEGISLATIVE_REVIEWER,
            ])],
            'legislative_name' => ['nullable', 'required_if:role,'.User::ROLE_COUNCILOR, 'string', 'min:3', 'max:255'],
            'legislative_party' => ['nullable', 'required_if:role,'.User::ROLE_COUNCILOR, 'string', 'min:2', 'max:30'],
            'legislative_term_start' => ['nullable', 'required_if:role,'.User::ROLE_COUNCILOR, 'date'],
            'legislative_term_end' => ['nullable', 'required_if:role,'.User::ROLE_COUNCILOR, 'date', 'after_or_equal:legislative_term_start'],
        ], [
            'legislative_name.required_if' => 'Informe o nome parlamentar do vereador.',
            'legislative_party.required_if' => 'Informe o partido do vereador.',
            'legislative_term_start.required_if' => 'Informe o início do mandato.',
            'legislative_term_end.required_if' => 'Informe o fim do mandato.',
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
                'legislative_name' => filled($validated['legislative_name'] ?? null) ? trim($validated['legislative_name']) : null,
                'legislative_party' => filled($validated['legislative_party'] ?? null) ? mb_strtoupper(trim($validated['legislative_party'])) : null,
                'legislative_term_start' => $validated['legislative_term_start'] ?? null,
                'legislative_term_end' => $validated['legislative_term_end'] ?? null,
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

    public function updateLegislativeIdentity(
        Request $request,
        int $user,
        CurrentMunicipality $currentMunicipality,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $validated = $request->validate([
            'legislative_name' => ['required', 'string', 'min:3', 'max:255'],
            'legislative_party' => ['required', 'string', 'min:2', 'max:30'],
            'legislative_term_start' => ['required', 'date'],
            'legislative_term_end' => ['required', 'date', 'after_or_equal:legislative_term_start'],
        ]);
        $municipality = $currentMunicipality->get($request);
        $member = $municipality->users()->wherePivot('role', User::ROLE_COUNCILOR)->findOrFail($user);
        $before = [
            'legislative_name' => $member->pivot->legislative_name,
            'legislative_party' => $member->pivot->legislative_party,
            'legislative_term_start' => $member->pivot->legislative_term_start,
            'legislative_term_end' => $member->pivot->legislative_term_end,
        ];
        $values = [
            'legislative_name' => trim($validated['legislative_name']),
            'legislative_party' => mb_strtoupper(trim($validated['legislative_party'])),
            'legislative_term_start' => $validated['legislative_term_start'],
            'legislative_term_end' => $validated['legislative_term_end'],
        ];
        DB::transaction(function () use ($request, $municipality, $member, $before, $values, $auditTrail): void {
            $municipality->users()->updateExistingPivot($member->id, $values);
            $auditTrail->recordMunicipalityOperation($request, $municipality, 'legislative_identity_updated', [
                'user_id' => $member->id, ...$values,
            ], $before);
        });

        return back()->with('status', "Identificação parlamentar de {$member->name} atualizada.");
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
