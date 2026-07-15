<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Municipality;
use App\Models\MunicipalityInvitation;
use App\Models\User;
use App\Notifications\MunicipalityInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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

    public function test_manager_can_create_secure_invitation_and_repeated_submission_is_ignored(): void
    {
        Notification::fake();
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $submissionToken = $this->submissionToken('municipality-invitation-create');

        $response = $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('users.invitations.store'), [
                '_submission_token' => $submissionToken,
                'email' => 'servidor@municipio.test',
                'role' => User::ROLE_EDITOR,
            ]);

        $response->assertRedirect(route('users.index'))->assertSessionHas('invitation_link');
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
}
