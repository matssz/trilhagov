<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LegislativeProposal;
use App\Models\Municipality;
use App\Models\MunicipalityInvitation;
use App\Models\MunicipalRegulatoryProfile;
use App\Models\User;
use App\Notifications\MunicipalityInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MunicipalUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_manager_can_open_user_management(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        [$editor, $editorMunicipality] = $this->memberWithMunicipality(User::ROLE_EDITOR);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('Usuários e acessos');

        $this->actingAs($editor)
            ->withSession(['active_municipality_id' => $editorMunicipality->id])
            ->get(route('users.index'))
            ->assertForbidden();
    }

    public function test_user_management_shows_usage_by_profile(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $councilor = User::factory()->create();
        $municipality->users()->attach($councilor, [
            'role' => User::ROLE_COUNCILOR,
            'legislative_name' => 'Bruno Almeida',
            'legislative_party' => 'PSD',
            'legislative_term_start' => '2025-01-01',
            'legislative_term_end' => '2028-12-31',
        ]);

        $proposal = $municipality->legislativeProposals()->create([
            'submitted_by' => $councilor->id,
            'reference' => 'LEG-2027-001',
            'fiscal_year' => 2027,
            'author_name' => 'Bruno Almeida',
            'author_party' => 'PSD',
            'object' => 'Aquisicao de mobiliario clinico',
            'justification' => 'Atender a unidade municipal de saude.',
            'priority' => 'normal',
            'beneficiary_type' => 'municipal_body',
            'beneficiary_name' => 'UBS Central',
            'beneficiary_location' => $municipality->name,
            'expense_destination' => 'investment',
            'transfer_type' => 'direct_execution',
            'health_related' => true,
            'responsible_department' => 'Secretaria Municipal de Saude',
            'public_need' => 'Melhorar o atendimento local.',
            'estimated_amount' => 25000,
            'estimate_source' => 'Pesquisa simplificada',
            'status' => LegislativeProposal::STATUS_DRAFT,
        ]);

        DB::table('audit_logs')->insert([
            'municipality_id' => $municipality->id,
            'user_id' => $councilor->id,
            'actor_name' => $councilor->name,
            'action' => 'created',
            'auditable_type' => LegislativeProposal::class,
            'auditable_id' => $proposal->id,
            'created_at' => now(),
        ]);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('Uso do sistema por perfil')
            ->assertSee('Ativos em 7 dias')
            ->assertSee('Vereador')
            ->assertSee('R$ 25.000,00');
    }

    public function test_manager_can_create_secure_invitation_and_repeated_submission_is_ignored(): void
    {
        Notification::fake();
        config(['mail.default' => 'smtp']);
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $submissionToken = $this->submissionToken('municipality-invitation-create');

        $response = $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('users.invitations.store'), [
                '_submission_token' => $submissionToken,
                'email' => 'servidor@municipio.test',
                'role' => User::ROLE_EDITOR,
            ]);

        $response->assertRedirect(route('users.index'))
            ->assertSessionHas('invitation_link')
            ->assertSessionHas('invitation_mail_status', 'sent');
        $acceptUrl = session('invitation_link');
        $rawToken = basename((string) parse_url($acceptUrl, PHP_URL_PATH));
        $invitation = MunicipalityInvitation::firstOrFail();

        $this->assertNotSame($rawToken, $invitation->token_hash);
        $this->assertSame(hash('sha256', $rawToken), $invitation->token_hash);
        $this->assertSame($manager->id, $invitation->invited_by);
        Notification::assertSentOnDemand(MunicipalityInvitationNotification::class);

        $this->post(route('users.invitations.store'), [
            '_submission_token' => $submissionToken,
            'email' => 'outro@municipio.test',
            'role' => User::ROLE_VIEWER,
        ])->assertSessionHas('warning');

        $this->assertDatabaseCount('municipality_invitations', 1);
    }

    public function test_log_mailer_keeps_manual_link_without_claiming_email_delivery(): void
    {
        Notification::fake();
        config(['mail.default' => 'log']);
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $this->activeProfile($municipality, $manager);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('users.invitations.store'), [
                '_submission_token' => $this->submissionToken('municipality-invitation-create'),
                'email' => 'vereador@municipio.test',
                'role' => User::ROLE_COUNCILOR,
                'legislative_name' => 'Vereador Teste',
                'legislative_party' => 'ABC',
                'legislative_term_start' => '2025-01-01',
                'legislative_term_end' => '2028-12-31',
            ])
            ->assertSessionHas('invitation_link')
            ->assertSessionHas('invitation_mail_status', 'unavailable');

        Notification::assertNothingSent();
    }

    public function test_manager_cannot_invite_legislative_access_without_active_exercise(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('users.invitations.store'), [
                '_submission_token' => $this->submissionToken('municipality-invitation-create'),
                'email' => 'vereador-sem-regra@municipio.test',
                'role' => User::ROLE_COUNCILOR,
                'legislative_name' => 'Vereador Sem Regra',
                'legislative_party' => 'PSD',
                'legislative_term_start' => '2025-01-01',
                'legislative_term_end' => '2028-12-31',
            ])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('municipality_invitations', [
            'email' => 'vereador-sem-regra@municipio.test',
        ]);
    }

    public function test_signed_in_manager_can_safely_switch_accounts_to_accept_new_user_invitation(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        [, $token] = $this->invitation($municipality, $manager, 'vereador@municipio.test', User::ROLE_COUNCILOR);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('invitations.show', $token))
            ->assertOk()
            ->assertSee('Este convite pertence a outra conta')
            ->assertSee('Sair e continuar com o convite');

        $this->post(route('invitations.switch-account', $token))
            ->assertRedirect(route('invitations.show', $token));

        $this->assertGuest();
        $this->get(route('invitations.show', $token))
            ->assertOk()
            ->assertSee('Aceitar convite')
            ->assertSee('vereador@municipio.test');
    }

    public function test_new_user_can_accept_invitation_only_once(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        [$invitation, $token] = $this->invitation($municipality, $manager, 'nova@municipio.test', User::ROLE_AUDITOR);

        $this->get(route('invitations.show', $token))
            ->assertOk()
            ->assertSee($municipality->name)
            ->assertSee('Auditoria');

        $this->post(route('invitations.accept', $token), [
            'name' => 'Nova Auditora',
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
        ])->assertRedirect(route('dashboard'));

        $user = User::query()->where('email', 'nova@municipio.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame($municipality->id, session('active_municipality_id'));
        $this->assertDatabaseHas('municipality_user', [
            'municipality_id' => $municipality->id,
            'user_id' => $user->id,
            'role' => User::ROLE_AUDITOR,
        ]);
        $this->assertNotNull($invitation->fresh()->accepted_at);

        $this->post(route('invitations.accept', $token))->assertNotFound();
        $this->assertDatabaseCount('users', 2);
    }

    public function test_existing_user_can_accept_access_to_another_municipality(): void
    {
        [$manager, $targetMunicipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        [$existingUser, $currentMunicipality] = $this->memberWithMunicipality(User::ROLE_VIEWER);
        [$invitation, $token] = $this->invitation(
            $targetMunicipality,
            $manager,
            $existingUser->email,
            User::ROLE_EDITOR,
        );

        $this->actingAs($existingUser)
            ->withSession(['active_municipality_id' => $currentMunicipality->id])
            ->post(route('invitations.accept', $token))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('municipality_user', [
            'municipality_id' => $targetMunicipality->id,
            'user_id' => $existingUser->id,
            'role' => User::ROLE_EDITOR,
        ]);
        $this->assertSame($targetMunicipality->id, session('active_municipality_id'));
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_expired_and_revoked_invitations_cannot_be_used(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        [$expired, $expiredToken] = $this->invitation($municipality, $manager, 'expirado@test.local');
        $expired->update(['expires_at' => now()->subMinute()]);
        [$revoked, $revokedToken] = $this->invitation($municipality, $manager, 'revogado@test.local');
        $revoked->update(['revoked_at' => now()]);

        $this->get(route('invitations.show', $expiredToken))->assertNotFound();
        $this->get(route('invitations.show', $revokedToken))->assertNotFound();
    }

    public function test_manager_can_change_role_and_the_change_is_audited(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $member = User::factory()->create();
        $municipality->users()->attach($member, ['role' => User::ROLE_VIEWER]);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->patch(route('users.role.update', $member), ['role' => User::ROLE_EDITOR])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('municipality_user', [
            'municipality_id' => $municipality->id,
            'user_id' => $member->id,
            'role' => User::ROLE_EDITOR,
        ]);

        $log = AuditLog::query()->where('action', 'role_updated')->firstOrFail();
        $this->assertSame(['role' => User::ROLE_VIEWER], $log->old_values);
        $this->assertSame(['role' => User::ROLE_EDITOR], $log->new_values);
        $this->assertSame(User::class, $log->auditable_type);
        $this->assertSame($member->id, $log->auditable_id);

        $this->patch(route('users.role.update', $member), ['role' => User::ROLE_EDITOR])
            ->assertSessionHas('warning');
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_manager_cannot_change_own_role_or_revoke_another_municipality_invitation(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        [$otherManager, $otherMunicipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        [$otherInvitation] = $this->invitation($otherMunicipality, $otherManager, 'fora@municipio.test');

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->patch(route('users.role.update', $manager), ['role' => User::ROLE_VIEWER])
            ->assertSessionHasErrors('role');

        $this->assertSame(User::ROLE_MANAGER, $manager->roleForMunicipality($municipality->id));

        $this->delete(route('users.invitations.destroy', $otherInvitation))->assertNotFound();
        $this->assertNull($otherInvitation->fresh()->revoked_at);
    }

    public function test_manager_can_cancel_invitation_and_invite_same_email_again(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        [$invitation] = $this->invitation($municipality, $manager, 'vereador@municipio.test', User::ROLE_COUNCILOR);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->delete(route('users.invitations.destroy', $invitation), [
                '_submission_token' => $this->submissionToken("municipality-invitation-revoke-{$invitation->id}"),
            ])
            ->assertSessionHas('status');

        $this->assertNotNull($invitation->fresh()->revoked_at);

        $this->post(route('users.invitations.store'), [
            '_submission_token' => $this->submissionToken('municipality-invitation-create'),
            'email' => 'vereador@municipio.test',
            'role' => User::ROLE_VIEWER,
        ])->assertSessionHas('invitation_link');

        $this->assertDatabaseHas('municipality_invitations', [
            'municipality_id' => $municipality->id,
            'email' => 'vereador@municipio.test',
            'revoked_at' => null,
        ]);
    }

    public function test_manager_removes_municipal_access_without_deleting_account_or_history(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $councilor = User::factory()->create();
        $municipality->users()->attach($councilor, [
            'role' => User::ROLE_COUNCILOR,
            'legislative_name' => 'Vereadora Maria Teste',
            'legislative_party' => 'ABC',
            'legislative_term_start' => '2025-01-01',
            'legislative_term_end' => '2028-12-31',
        ]);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('Vereadora Maria Teste')
            ->assertSee('ABC')
            ->assertSee('value="2025-01-01"', false)
            ->assertSee('value="2028-12-31"', false);

        $this->delete(route('users.destroy', $councilor), [
            '_submission_token' => $this->submissionToken("municipality-member-remove-{$councilor->id}"),
        ])
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('municipality_user', [
            'municipality_id' => $municipality->id,
            'user_id' => $councilor->id,
        ]);
        $this->assertDatabaseHas('users', ['id' => $councilor->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'municipal_user_access_removed']);
    }

    public function test_manager_cannot_remove_own_access(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->delete(route('users.destroy', $manager))
            ->assertSessionHasErrors('user');

        $this->assertDatabaseHas('municipality_user', [
            'municipality_id' => $municipality->id,
            'user_id' => $manager->id,
        ]);
    }

    /** @return array{User, Municipality} */
    private function memberWithMunicipality(string $role): array
    {
        $user = User::factory()->create();
        $municipality = Municipality::factory()->create();
        $municipality->users()->attach($user, ['role' => $role]);

        return [$user, $municipality];
    }

    /** @return array{MunicipalityInvitation, string} */
    private function invitation(
        Municipality $municipality,
        User $inviter,
        string $email,
        string $role = User::ROLE_VIEWER,
    ): array {
        $token = Str::random(64);
        $invitation = MunicipalityInvitation::create([
            'municipality_id' => $municipality->id,
            'invited_by' => $inviter->id,
            'email' => $email,
            'role' => $role,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
        ]);

        return [$invitation, $token];
    }

    private function submissionToken(string $scope): string
    {
        $token = (string) Str::uuid();

        $this->withSession([
            'form_submission_tokens' => [
                $scope => [$token => now()->timestamp],
            ],
        ]);

        return $token;
    }

    private function activeProfile(Municipality $municipality, User $manager): MunicipalRegulatoryProfile
    {
        return $municipality->regulatoryProfiles()->create([
            'created_by' => $manager->id,
            'updated_by' => $manager->id,
            'activated_by' => $manager->id,
            'audesp_responsible_user_id' => $manager->id,
            'fiscal_year' => 2027,
            'version' => 1,
            'status' => MunicipalRegulatoryProfile::STATUS_ACTIVE,
            'regime_status' => MunicipalRegulatoryProfile::REGIME_INSTITUTED,
            'previous_year_rcl' => 200000000,
            'individual_limit_percentage' => 1.55,
            'councilor_seats' => 13,
            'health_reserve_percentage' => 50,
            'health_reserve_method' => 'per_councilor',
            'amendments_per_councilor_limit' => 20,
            'minimum_amendment_amount' => 1,
            'generic_amendments_prohibited' => true,
            'prior_technical_review_required' => true,
            'work_plan_required' => true,
            'pca_check_required' => true,
            'impediment_notice_days' => 30,
            'impediment_correction_days' => 30,
            'publication_business_days' => 5,
            'document_retention_years' => 10,
            'bank_traceability_rule' => 'direct_execution_traceability',
            'audesp_registration_status' => 'ready',
            'legal_review_responsible' => 'Procuradoria Municipal',
            'legal_review_reference' => 'Parecer 001/2027',
            'legal_reviewed_at' => today(),
            'activated_at' => now(),
        ]);
    }
}
