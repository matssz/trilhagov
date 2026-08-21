<?php

namespace Tests\Feature;

use App\Models\Municipality;
use App\Models\MunicipalNormativeInstrument;
use App\Models\MunicipalRegulatoryProfile;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use App\Services\LegislativeProposalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class MunicipalRegulatoryProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_member_can_consult_but_only_manager_can_change_municipal_rules(): void
    {
        [$editor, $municipality] = $this->member(User::ROLE_EDITOR);
        $profile = $this->profile($municipality, $editor);

        $this->actingAs($editor)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('municipal-rules.index'))
            ->assertOk()
            ->assertSee('Normas municipais')
            ->assertSee((string) $profile->fiscal_year);

        $this->post(route('municipal-rules.store'), [
            '_submission_token' => (string) Str::uuid(),
            'fiscal_year' => 2027,
        ])->assertForbidden();
    }

    public function test_empty_rules_page_guides_manager_to_activate_first_exercise(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('municipal-rules.index'))
            ->assertOk()
            ->assertSee('Nenhum exercício configurado')
            ->assertSee('Novo exercício')
            ->assertSee('Módulos como importação de planilha');
    }

    public function test_empty_rules_page_does_not_show_activation_hint_to_non_manager(): void
    {
        [$editor, $municipality] = $this->member(User::ROLE_EDITOR);

        $this->actingAs($editor)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('municipal-rules.index'))
            ->assertOk()
            ->assertSee('Nenhum exercício configurado')
            ->assertDontSee('Módulos como importação de planilha');
    }

    public function test_legacy_profile_values_do_not_break_municipal_rules_page(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $profile = $this->profile($municipality, $manager);

        $profile->forceFill([
            'status' => MunicipalRegulatoryProfile::STATUS_ACTIVE,
            'regime_status' => 'legacy_status',
            'health_reserve_method' => 'legacy_method',
            'audesp_registration_status' => 'legacy_status',
        ])->saveQuietly();

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('municipal-rules.index'))
            ->assertOk()
            ->assertSee('legacy_status')
            ->assertSee('Metodo nao definido')
            ->assertSee('Nao informado');

        $emptyProfile = new MunicipalRegulatoryProfile;
        $this->assertSame('Situacao nao informada', $emptyProfile->statusLabel());
        $this->assertSame('Situacao normativa nao informada', $emptyProfile->regimeStatusLabel());
    }

    public function test_manager_starts_only_one_draft_per_year_even_when_request_is_repeated(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $token = $this->submissionSession($municipality, 'municipal-rules-create');

        $this->actingAs($manager)->post(route('municipal-rules.store'), [
            '_submission_token' => $token,
            'fiscal_year' => 2027,
        ])->assertRedirect();

        $this->post(route('municipal-rules.store'), [
            '_submission_token' => $token,
            'fiscal_year' => 2027,
        ])->assertSessionHas('warning');

        $this->assertDatabaseCount('municipal_regulatory_profiles', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'municipal_rules_created']);
    }

    public function test_incomplete_configuration_cannot_be_activated(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $profile = $this->profile($municipality, $manager);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('municipal-rules.activate', $profile))
            ->assertSessionHasErrors('activation');

        $this->assertSame(MunicipalRegulatoryProfile::STATUS_DRAFT, $profile->fresh()->status);
    }

    public function test_manager_can_apply_organic_law_defaults_without_typing_budget_percentages(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $profile = $this->profile($municipality, $manager);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->patch(route('municipal-rules.update', $profile), [
                'apply_organic_law_defaults' => '1',
                'regime_status' => MunicipalRegulatoryProfile::REGIME_UNDER_REVIEW,
                'previous_year_rcl' => 100000000,
                'councilor_seats' => 10,
                'audesp_registration_status' => 'not_started',
            ])
            ->assertSessionHas('status');

        $profile->refresh();
        $this->assertSame(MunicipalRegulatoryProfile::REGIME_INSTITUTED, $profile->regime_status);
        $this->assertSame('1.5500', $profile->individual_limit_percentage);
        $this->assertSame('50.0000', $profile->health_reserve_percentage);
        $this->assertSame('per_councilor', $profile->health_reserve_method);
        $this->assertTrue($profile->work_plan_required);
        $this->assertSame(155000.0, app(LegislativeProposalService::class)->quota($municipality, $profile, 'Vereador')['author_ceiling']);
    }

    public function test_linking_organic_law_applies_defaults_to_empty_draft(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $profile = $this->profile($municipality, $manager);
        $token = $this->submissionSession($municipality, "municipal-rules-instrument-{$profile->id}");

        $this->actingAs($manager)->post(route('municipal-rules.instruments.store', $profile), [
            '_submission_token' => $token,
            'type' => 'organic_law',
            'title' => 'Lei Organica Municipal',
            'reference' => 'LOM/2026',
        ])->assertSessionHas('status', 'Lei Organica vinculada. Parametros-padrao aplicados automaticamente; informe apenas RCL e cadeiras se ainda estiverem pendentes.');

        $profile->refresh();
        $this->assertSame(MunicipalRegulatoryProfile::REGIME_INSTITUTED, $profile->regime_status);
        $this->assertSame('1.5500', $profile->individual_limit_percentage);
        $this->assertSame('50.0000', $profile->health_reserve_percentage);
        $this->assertSame('per_councilor', $profile->health_reserve_method);
        $this->assertTrue($profile->generic_amendments_prohibited);
    }

    public function test_manager_can_attach_a_document_to_a_normative_instrument(): void
    {
        Storage::fake('local');
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $profile = $this->profile($municipality, $manager);
        $token = $this->submissionSession($municipality, "municipal-rules-instrument-{$profile->id}");

        $this->actingAs($manager)->post(route('municipal-rules.instruments.store', $profile), [
            '_submission_token' => $token,
            'type' => 'organic_law',
            'title' => 'Lei Organica Municipal',
            'reference' => 'LOM/2026',
            'document' => UploadedFile::fake()->create('lei-organica.pdf', 200, 'application/pdf'),
        ])->assertSessionHas('status');

        $instrument = MunicipalNormativeInstrument::firstOrFail();
        $this->assertTrue($instrument->hasFile());
        $this->assertSame($manager->id, $instrument->uploaded_by);
        $this->assertSame('lei-organica.pdf', $instrument->original_name);
        Storage::disk('local')->assertExists($instrument->storage_path);
        $this->assertDatabaseHas('audit_logs', [
            'municipality_id' => $municipality->id,
            'user_id' => $manager->id,
            'action' => 'municipal_instrument_created',
        ]);

        $this->get(route('municipal-rules.instruments.download', [$profile, $instrument]))
            ->assertOk()
            ->assertDownload('lei-organica.pdf');
    }

    public function test_normative_instrument_document_is_rejected_when_unsafe_or_too_large(): void
    {
        Storage::fake('local');
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $profile = $this->profile($municipality, $manager);
        $token = $this->submissionSession($municipality, "municipal-rules-instrument-{$profile->id}");

        $this->actingAs($manager)->post(route('municipal-rules.instruments.store', $profile), [
            '_submission_token' => $token,
            'type' => 'other',
            'title' => 'Documento inseguro',
            'reference' => 'X/2026',
            'document' => UploadedFile::fake()->create('programa.exe', 20, 'application/x-msdownload'),
        ])->assertSessionHasErrors('document');

        $this->post(route('municipal-rules.instruments.store', $profile), [
            '_submission_token' => $token,
            'type' => 'other',
            'title' => 'Documento grande',
            'reference' => 'X/2026',
            'document' => UploadedFile::fake()->create('grande.pdf', 11000, 'application/pdf'),
        ])->assertSessionHasErrors('document');

        $this->assertDatabaseCount('municipal_normative_instruments', 0);
    }

    public function test_normative_instrument_document_from_another_municipality_cannot_be_downloaded(): void
    {
        Storage::fake('local');
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        [$otherManager, $otherMunicipality] = $this->member(User::ROLE_MANAGER);
        $otherProfile = $this->profile($otherMunicipality, $otherManager);
        $otherInstrument = $otherProfile->instruments()->create([
            'municipality_id' => $otherMunicipality->id,
            'created_by' => $otherManager->id,
            'type' => 'organic_law',
            'title' => 'Lei Organica de outro municipio',
            'reference' => 'LOM/2026',
            'uploaded_by' => $otherManager->id,
            'original_name' => 'lei-organica.pdf',
            'storage_path' => Storage::disk('local')->putFileAs('normative-instruments/test', UploadedFile::fake()->create('lei-organica.pdf', 50, 'application/pdf'), 'lei-organica.pdf'),
            'mime_type' => 'application/pdf',
            'size_bytes' => 51200,
        ]);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('municipal-rules.instruments.download', [$otherProfile, $otherInstrument]))
            ->assertNotFound();
    }

    public function test_manager_can_activate_a_complete_review_and_it_becomes_immutable(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $profile = $this->completeProfile($municipality, $manager);
        $amendment = ParliamentaryAmendment::factory()
            ->for($municipality)
            ->for($manager, 'creator')
            ->create(['fiscal_year' => 2026, 'government_sphere' => 'municipal']);
        $impediment = $amendment->technicalImpediments()->create([
            'municipality_id' => $municipality->id,
            'created_by' => $manager->id,
            'category' => 'technical',
            'nature' => 'under_analysis',
            'status' => 'identified',
            'title' => 'Pendência técnica',
            'description' => 'Descrição suficiente para o teste.',
            'impact' => 'Execução temporariamente suspensa.',
            'identified_at' => today(),
        ]);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('municipal-rules.activate', $profile))
            ->assertSessionHas('status');

        $profile->refresh();
        $this->assertSame(MunicipalRegulatoryProfile::STATUS_ACTIVE, $profile->status);
        $this->assertNotNull($profile->activated_at);
        $this->assertSame($profile->id, $amendment->fresh()->municipal_regulatory_profile_id);
        $this->assertSame($profile->id, $impediment->fresh()->municipal_regulatory_profile_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'municipal_rules_activated']);

        $this->expectException(LogicException::class);
        $profile->update(['notes' => 'Tentativa de alteração indevida']);
    }

    public function test_revision_copies_parameters_and_instruments_without_changing_active_version(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $source = $this->completeProfile($municipality, $manager);
        $source->update(['status' => MunicipalRegulatoryProfile::STATUS_ACTIVE, 'activated_at' => now()]);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('municipal-rules.revise', $source))
            ->assertRedirect();

        $copy = MunicipalRegulatoryProfile::query()->where('status', MunicipalRegulatoryProfile::STATUS_DRAFT)->firstOrFail();
        $this->assertSame(2, $copy->version);
        $this->assertSame($source->individual_limit_percentage, $copy->individual_limit_percentage);
        $this->assertSame($source->instruments()->count(), $copy->instruments()->count());
        $this->assertSame(MunicipalRegulatoryProfile::STATUS_ACTIVE, $source->fresh()->status);
    }

    public function test_profile_and_instrument_ids_from_another_municipality_are_not_accessible(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        [$otherManager, $otherMunicipality] = $this->member(User::ROLE_MANAGER);
        $foreignProfile = $this->profile($otherMunicipality, $otherManager);
        $foreignInstrument = $this->instrument($foreignProfile, $otherManager, 'organic_law');

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('municipal-rules.activate', $foreignProfile))
            ->assertNotFound();

        $this->delete(route('municipal-rules.instruments.destroy', [$foreignProfile, $foreignInstrument]))
            ->assertNotFound();
    }

    /** @return array{User, Municipality} */
    private function member(string $role): array
    {
        $user = User::factory()->create();
        $municipality = Municipality::factory()->create(['state' => 'SP']);
        $municipality->users()->attach($user, ['role' => $role]);

        return [$user, $municipality];
    }

    private function profile(Municipality $municipality, User $user): MunicipalRegulatoryProfile
    {
        return $municipality->regulatoryProfiles()->create([
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'fiscal_year' => 2026,
            'version' => 1,
        ]);
    }

    private function completeProfile(Municipality $municipality, User $user): MunicipalRegulatoryProfile
    {
        $profile = $this->profile($municipality, $user);
        $profile->update([
            'regime_status' => MunicipalRegulatoryProfile::REGIME_INSTITUTED,
            'previous_year_rcl' => 100000000,
            'individual_limit_percentage' => 1.55,
            'councilor_seats' => 10,
            'health_reserve_percentage' => 50,
            'health_reserve_method' => 'global',
            'generic_amendments_prohibited' => true,
            'prior_technical_review_required' => true,
            'work_plan_required' => true,
            'pca_check_required' => true,
            'impediment_notice_days' => 30,
            'impediment_correction_days' => 30,
            'publication_business_days' => 1,
            'document_retention_years' => 5,
            'bank_traceability_rule' => 'individual_account',
            'audesp_registration_status' => 'ready',
            'audesp_responsible_user_id' => $user->id,
            'legal_review_responsible' => 'Procuradoria Municipal',
            'legal_review_reference' => 'Parecer Jurídico 12/2026',
            'legal_reviewed_at' => today(),
        ]);
        foreach (['organic_law', 'internal_rules', 'ppa', 'ldo', 'loa', 'regulation'] as $type) {
            $this->instrument($profile, $user, $type);
        }

        return $profile->fresh('instruments');
    }

    private function instrument(MunicipalRegulatoryProfile $profile, User $user, string $type): MunicipalNormativeInstrument
    {
        return $profile->instruments()->create([
            'municipality_id' => $profile->municipality_id,
            'created_by' => $user->id,
            'type' => $type,
            'title' => MunicipalNormativeInstrument::types()[$type],
            'reference' => strtoupper($type).'/2026',
        ]);
    }

    private function submissionSession(Municipality $municipality, string $scope): string
    {
        $token = (string) Str::uuid();
        $this->withSession([
            'active_municipality_id' => $municipality->id,
            'form_submission_tokens' => [$scope => [$token => now()->timestamp]],
        ]);

        return $token;
    }
}
