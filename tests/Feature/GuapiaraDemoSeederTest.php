<?php

namespace Tests\Feature;

use App\Models\AccountabilityProcess;
use App\Models\LegislativeProposal;
use App\Models\Municipality;
use App\Models\MunicipalRegulatoryProfile;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use Database\Seeders\GuapiaraDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuapiaraDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_guapiara_demo_creates_complete_presentation_base(): void
    {
        $this->seed(GuapiaraDemoSeeder::class);

        $municipality = Municipality::where('ibge_code', '3517604')->firstOrFail();

        $this->assertSame('Guapiara', $municipality->name);
        $this->assertTrue($municipality->supportsTcespAudesp());
        $this->assertTrue($municipality->spreadsheet_import_enabled);
        $this->assertTrue($municipality->document_checklist_enabled);
        $this->assertFalse($municipality->federal_amendments_enabled);
        $this->assertFalse($municipality->state_amendments_enabled);

        $bruno = User::where('email', 'bruno.guapiara@trilhagov.demo')->firstOrFail();

        $this->assertSame(User::ROLE_COUNCILOR, $bruno->roleForMunicipality($municipality->id));
        $this->assertDatabaseHas('municipal_regulatory_profiles', [
            'municipality_id' => $municipality->id,
            'fiscal_year' => 2027,
            'status' => MunicipalRegulatoryProfile::STATUS_ACTIVE,
        ]);

        $this->assertGreaterThanOrEqual(5, LegislativeProposal::where('municipality_id', $municipality->id)->count());
        $this->assertDatabaseHas('legislative_proposals', [
            'municipality_id' => $municipality->id,
            'reference' => 'LEG-GUA-2027-001',
            'status' => LegislativeProposal::STATUS_SUBMITTED,
        ]);
        $this->assertDatabaseHas('legislative_proposals', [
            'municipality_id' => $municipality->id,
            'reference' => 'LEG-GUA-2027-002',
            'status' => LegislativeProposal::STATUS_APPROVED,
        ]);
        $this->assertDatabaseHas('legislative_proposals', [
            'municipality_id' => $municipality->id,
            'reference' => 'LEG-GUA-2027-005',
            'status' => LegislativeProposal::STATUS_RECEIVED,
        ]);
        $this->assertDatabaseHas('parliamentary_amendments', [
            'municipality_id' => $municipality->id,
            'reference' => 'EM-GUA-2027-003',
            'status' => ParliamentaryAmendment::STATUS_ACCOUNTABILITY_PENDING,
        ]);
        $this->assertDatabaseHas('parliamentary_amendments', [
            'municipality_id' => $municipality->id,
            'reference' => 'EM-GUA-2027-005',
            'status' => ParliamentaryAmendment::STATUS_EXECUTING,
        ]);
        $this->assertDatabaseHas('parliamentary_amendments', [
            'municipality_id' => $municipality->id,
            'reference' => 'EM-GUA-2027-004',
            'status' => ParliamentaryAmendment::STATUS_COMPLETED,
        ]);
        $this->assertSame(2, AccountabilityProcess::where('municipality_id', $municipality->id)->count());
    }
}
