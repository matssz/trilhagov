<?php

namespace Database\Seeders;

use App\Models\AccountabilityProcess;
use App\Models\AccountabilityRequirement;
use App\Models\DocumentType;
use App\Models\ExecutionStage;
use App\Models\FinancialCommitment;
use App\Models\LegislativeProposal;
use App\Models\MunicipalInstitution;
use App\Models\Municipality;
use App\Models\MunicipalRegulatoryProfile;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class GuapiaraDemoSeeder extends Seeder
{
    private const PASSWORD = 'TrilhaGov@2027';

    public function run(): void
    {
        DB::transaction(function (): void {
            $manager = $this->user('Gestor Guapiara', 'gestor.guapiara@trilhagov.demo');
            $executive = $this->user('Executivo Guapiara', 'executivo.guapiara@trilhagov.demo');
            $legislative = $this->user('Câmara Guapiara', 'camara.guapiara@trilhagov.demo');
            $auditor = $this->user('Controle Interno Guapiara', 'controle.guapiara@trilhagov.demo');

            $municipality = Municipality::updateOrCreate(
                ['ibge_code' => '3517604'],
                [
                    'name' => 'Guapiara',
                    'state' => 'SP',
                    'cnpj' => '46634275000188',
                    'federal_amendments_enabled' => false,
                    'state_amendments_enabled' => false,
                    'health_asps_module_enabled' => true,
                    'contracts_module_enabled' => true,
                    'audit_module_enabled' => true,
                    'specialized_reports_enabled' => true,
                    'spreadsheet_import_enabled' => true,
                    'document_checklist_enabled' => true,
                    'transparency_enabled' => true,
                    'transparency_slug' => 'guapiara-demo',
                    'transparency_updated_at' => now(),
                ],
            );

            $this->attach($municipality, $manager, User::ROLE_MANAGER);
            $this->attach($municipality, $executive, User::ROLE_EDITOR);
            $this->attach($municipality, $legislative, User::ROLE_LEGISLATIVE_REVIEWER);
            $this->attach($municipality, $auditor, User::ROLE_AUDITOR);

            $councilors = collect([
                ['Bruno Almeida', 'PSD', 'bruno.guapiara@trilhagov.demo'],
                ['Darcizo Jacinto de Lara', 'PP', 'darcizo.guapiara@trilhagov.demo'],
                ['Flavio Augusto Ferreira Menk', 'PODE', 'flavio.menk.guapiara@trilhagov.demo'],
                ['Flavio Rodrigues Alves', 'UNIÃO', 'flavio.alves.guapiara@trilhagov.demo'],
                ['Jaine Venancio Santos', 'PL', 'jaine.guapiara@trilhagov.demo'],
                ['João Batista Romualdo', 'PL', 'joao.romualdo.guapiara@trilhagov.demo'],
                ['Josias Gonçalves', 'UNIÃO', 'josias.guapiara@trilhagov.demo'],
                ['Mauricio Ernandes Kerche Paes', 'REPUBLICANOS', 'mauricio.guapiara@trilhagov.demo'],
                ['Neli da Costa Silva Barros', 'PP', 'neli.guapiara@trilhagov.demo'],
                ['Rosnei Mauricio Alves', 'REPUBLICANOS', 'rosnei.guapiara@trilhagov.demo'],
                ['Waldir Gonzaga dos Santos Junior', 'PP', 'waldir.guapiara@trilhagov.demo'],
                ['Wilian Matheus Pontes Camargo', 'PSB', 'wilian.guapiara@trilhagov.demo'],
            ])->map(function (array $row) use ($municipality, $manager): User {
                [$name, $party, $email] = $row;
                $user = $this->user($name, $email);
                $this->attach($municipality, $user, User::ROLE_COUNCILOR, [
                    'legislative_name' => $name,
                    'legislative_party' => $party,
                    'legislative_term_start' => '2025-01-01',
                    'legislative_term_end' => '2028-12-31',
                ]);

                MunicipalInstitution::updateOrCreate(
                    ['municipality_id' => $municipality->id, 'type' => MunicipalInstitution::TYPE_COUNCILOR, 'name' => $name],
                    [
                        'created_by' => $manager->id,
                        'updated_by' => $manager->id,
                        'party' => $party,
                        'role_title' => 'Vereador',
                        'email' => $email,
                        'city' => 'Guapiara',
                        'state' => 'SP',
                        'is_active' => true,
                    ],
                );

                return $user;
            });

            foreach ([
                ['Secretaria Municipal de Saúde', MunicipalInstitution::TYPE_DEPARTMENT, 'Saúde'],
                ['Secretaria Municipal de Educação', MunicipalInstitution::TYPE_DEPARTMENT, 'Educação'],
                ['Secretaria Municipal de Obras e Serviços', MunicipalInstitution::TYPE_DEPARTMENT, 'Obras'],
                ['Câmara Municipal de Guapiara', MunicipalInstitution::TYPE_EXECUTING_UNIT, 'Legislativo'],
                ['UBS Centro', MunicipalInstitution::TYPE_BENEFICIARY, 'Saúde'],
                ['Escola Municipal Vila Nova', MunicipalInstitution::TYPE_BENEFICIARY, 'Educação'],
                ['Fiscalização Municipal de Contratos', MunicipalInstitution::TYPE_INSPECTOR, 'Controle'],
            ] as [$name, $type, $department]) {
                MunicipalInstitution::updateOrCreate(
                    ['municipality_id' => $municipality->id, 'type' => $type, 'name' => $name],
                    [
                        'created_by' => $manager->id,
                        'updated_by' => $manager->id,
                        'department' => $department,
                        'city' => 'Guapiara',
                        'state' => 'SP',
                        'is_active' => true,
                    ],
                );
            }

            $profile = $this->profile($municipality, $manager);
            $this->normativeInstruments($municipality, $profile, $manager);
            DocumentType::createDefaultsFor($municipality);

            $this->proposal(
                $municipality,
                $profile,
                $councilors[0],
                'LEG-GUA-2027-001',
                LegislativeProposal::STATUS_SUBMITTED,
                'Aquisição de equipamentos odontológicos para a UBS Centro',
                'Secretaria Municipal de Saúde',
                'UBS Centro',
                85000,
                true,
            );
            $this->proposal(
                $municipality,
                $profile,
                $councilors[1],
                'LEG-GUA-2027-002',
                LegislativeProposal::STATUS_APPROVED,
                'Adequação de sala de informática da Escola Municipal Vila Nova',
                'Secretaria Municipal de Educação',
                'Escola Municipal Vila Nova',
                68000,
                false,
                $legislative,
            );

            $reservedProposal = $this->proposal(
                $municipality,
                $profile,
                $councilors[7],
                'LEG-GUA-2027-003',
                LegislativeProposal::STATUS_RESERVED,
                'Equipamentos para sala de vacinação da UBS Centro',
                'Secretaria Municipal de Saúde',
                'UBS Centro',
                95000,
                true,
                $legislative,
            );

            $amendment = $this->amendment($municipality, $profile, $manager, $executive, $reservedProposal);
            $reservedProposal->forceFill(['parliamentary_amendment_id' => $amendment->id])->save();
            $this->executionAndAccountability($municipality, $amendment, $manager, $executive);

            $this->command?->info('Base demonstrativa de Guapiara criada/atualizada.');
            $this->command?->table(
                ['Perfil', 'Email', 'Senha'],
                [
                    ['Gestor', 'gestor.guapiara@trilhagov.demo', self::PASSWORD],
                    ['Executivo', 'executivo.guapiara@trilhagov.demo', self::PASSWORD],
                    ['Câmara', 'camara.guapiara@trilhagov.demo', self::PASSWORD],
                    ['Controle interno', 'controle.guapiara@trilhagov.demo', self::PASSWORD],
                    ['Vereador Bruno', 'bruno.guapiara@trilhagov.demo', self::PASSWORD],
                    ['Vereador realista', 'darcizo.guapiara@trilhagov.demo', self::PASSWORD],
                ],
            );
        });
    }

    private function user(string $name, string $email): User
    {
        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => self::PASSWORD,
            'email_verified_at' => now(),
            'mfa_enabled' => false,
        ])->save();

        return $user;
    }

    /** @param array<string, mixed> $pivot */
    private function attach(Municipality $municipality, User $user, string $role, array $pivot = []): void
    {
        $values = array_merge([
            'role' => $role,
            'notify_in_app' => true,
            'notify_email' => false,
            'notify_deadlines' => true,
            'notify_integrity' => true,
            'legislative_name' => null,
            'legislative_party' => null,
            'legislative_term_start' => null,
            'legislative_term_end' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $pivot);

        $municipality->users()->syncWithoutDetaching([$user->id => $values]);
        $municipality->users()->updateExistingPivot($user->id, $values);
    }

    private function profile(Municipality $municipality, User $manager): MunicipalRegulatoryProfile
    {
        DB::table('municipal_regulatory_profiles')->updateOrInsert(
            ['municipality_id' => $municipality->id, 'fiscal_year' => 2027, 'version' => 1],
            [
                'created_by' => $manager->id,
                'updated_by' => $manager->id,
                'activated_by' => $manager->id,
                'fiscal_year' => 2027,
                'status' => MunicipalRegulatoryProfile::STATUS_ACTIVE,
                'regime_status' => MunicipalRegulatoryProfile::REGIME_INSTITUTED,
                'previous_year_rcl' => 95000000,
                'individual_limit_percentage' => 1.2,
                'councilor_seats' => 11,
                'health_reserve_percentage' => 50,
                'health_reserve_method' => 'global',
                'amendments_per_councilor_limit' => 4,
                'minimum_amendment_amount' => 5000,
                'generic_amendments_prohibited' => true,
                'prior_technical_review_required' => true,
                'work_plan_required' => true,
                'pca_check_required' => true,
                'impediment_notice_days' => 30,
                'impediment_correction_days' => 15,
                'publication_business_days' => 5,
                'document_retention_years' => 10,
                'bank_traceability_rule' => 'municipal_direct_codes',
                'audesp_registration_status' => 'ready',
                'legal_review_responsible' => 'Procuradoria Municipal',
                'legal_review_reference' => 'Parecer jurídico demonstrativo 001/2026',
                'legal_reviewed_at' => '2026-08-10',
                'notes' => 'Base demonstrativa para apresentação do fluxo municipal de emendas impositivas.',
                'activated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return MunicipalRegulatoryProfile::where('municipality_id', $municipality->id)
            ->where('fiscal_year', 2027)
            ->where('version', 1)
            ->firstOrFail();
    }

    private function normativeInstruments(Municipality $municipality, MunicipalRegulatoryProfile $profile, User $manager): void
    {
        foreach ([
            ['organic_law', 'Lei Orgânica Municipal', 'Regra demonstrativa de emendas impositivas 2027'],
            ['ldo', 'Lei de Diretrizes Orçamentárias 2027', 'LDO demonstrativa 2027'],
            ['loa', 'Lei Orçamentária Anual 2027', 'LOA demonstrativa 2027'],
            ['regulation', 'Decreto de execução das emendas 2027', 'Decreto demonstrativo 001/2027'],
        ] as [$type, $title, $reference]) {
            DB::table('municipal_normative_instruments')->updateOrInsert(
                ['municipality_id' => $municipality->id, 'municipal_regulatory_profile_id' => $profile->id, 'type' => $type],
                [
                    'created_by' => $manager->id,
                    'title' => $title,
                    'reference' => $reference,
                    'enacted_at' => '2026-08-10',
                    'effective_from' => '2027-01-01',
                    'notes' => 'Instrumento demonstrativo para liberar cotas, reserva de saúde e Portal Legislativo.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function proposal(
        Municipality $municipality,
        MunicipalRegulatoryProfile $profile,
        User $councilor,
        string $reference,
        string $status,
        string $object,
        string $department,
        string $beneficiary,
        float $amount,
        bool $health,
        ?User $reviewer = null,
    ): LegislativeProposal {
        $pivot = $councilor->municipalities()->whereKey($municipality->id)->first()?->pivot;

        return LegislativeProposal::updateOrCreate(
            ['municipality_id' => $municipality->id, 'reference' => $reference],
            [
                'municipal_regulatory_profile_id' => $profile->id,
                'submitted_by' => $councilor->id,
                'reviewed_by' => $reviewer?->id,
                'fiscal_year' => 2027,
                'author_name' => (string) ($pivot?->legislative_name ?: $councilor->name),
                'author_party' => (string) ($pivot?->legislative_party ?: 'Sem partido'),
                'object' => $object,
                'justification' => 'Pedido demonstrativo vinculado a necessidade pública municipal, com objeto definido, valor estimado e destino informado.',
                'priority' => 'normal',
                'beneficiary_type' => 'municipal_body',
                'beneficiary_name' => $beneficiary,
                'beneficiary_location' => 'Guapiara/SP',
                'expense_destination' => 'investment',
                'transfer_type' => 'direct_execution',
                'health_related' => $health,
                'responsible_department' => $department,
                'program_reference' => $health ? 'Atenção básica municipal' : 'Infraestrutura escolar',
                'action_reference' => $health ? 'Estruturação de unidades de saúde' : 'Modernização de unidades escolares',
                'public_need' => 'Melhorar o atendimento direto à população e reduzir riscos operacionais.',
                'target_population' => $health ? 'Usuários da rede municipal de saúde' : 'Alunos da rede municipal',
                'estimated_quantity' => '1 conjunto de itens priorizados pela secretaria responsável',
                'estimated_amount' => $amount,
                'estimate_source' => 'Estimativa demonstrativa para apresentação do piloto',
                'desired_contract_at' => '2027-03-15',
                'status' => $status,
                'review_ppa' => $reviewer !== null,
                'review_ldo' => $reviewer !== null,
                'review_loa' => $reviewer !== null,
                'review_sector_plan' => $reviewer !== null,
                'review_budget_limit' => $reviewer !== null,
                'review_health_reserve' => $reviewer !== null,
                'review_object' => $reviewer !== null,
                'review_beneficiary' => $reviewer !== null,
                'review_viability' => $reviewer !== null,
                'review_notes' => $reviewer ? 'Conferência legislativa demonstrativa aprovada para apresentação.' : null,
                'protocol_number' => in_array($status, [LegislativeProposal::STATUS_APPROVED, LegislativeProposal::STATUS_SENT, LegislativeProposal::STATUS_RECEIVED, LegislativeProposal::STATUS_RESERVED], true) ? 'CAM-GUA-2027-'.substr($reference, -3) : null,
                'executive_process_number' => $status === LegislativeProposal::STATUS_RESERVED ? 'PM-GUA-2027-003' : null,
                'budget_reservation_number' => $status === LegislativeProposal::STATUS_RESERVED ? 'RES-GUA-2027-003' : null,
                'budget_reserved_amount' => $status === LegislativeProposal::STATUS_RESERVED ? $amount : null,
                'budget_reserved_at' => $status === LegislativeProposal::STATUS_RESERVED ? '2027-02-10' : null,
                'submitted_at' => now()->subDays(18),
                'reviewed_at' => $reviewer ? now()->subDays(12) : null,
                'sent_at' => in_array($status, [LegislativeProposal::STATUS_SENT, LegislativeProposal::STATUS_RECEIVED, LegislativeProposal::STATUS_RESERVED], true) ? now()->subDays(9) : null,
                'received_at' => $status === LegislativeProposal::STATUS_RESERVED ? now()->subDays(7) : null,
            ],
        );
    }

    private function amendment(
        Municipality $municipality,
        MunicipalRegulatoryProfile $profile,
        User $manager,
        User $executive,
        LegislativeProposal $proposal,
    ): ParliamentaryAmendment {
        $amendment = ParliamentaryAmendment::firstOrNew(['municipality_id' => $municipality->id, 'reference' => 'EM-GUA-2027-003']);
        $amendment->forceFill([
            'created_by' => $manager->id,
            'municipal_regulatory_profile_id' => $profile->id,
            'fiscal_year' => 2027,
            'government_sphere' => 'municipal',
            'authorship_type' => 'individual',
            'transfer_type' => 'direct_execution',
            'author_name' => $proposal->author_name,
            'author_party' => $proposal->author_party,
            'object' => $proposal->object,
            'indicated_for_health' => true,
            'expense_destination' => 'investment',
            'responsible_department' => $proposal->responsible_department,
            'beneficiary_location' => 'UBS Centro - Guapiara/SP',
            'responsible_user_id' => $executive->id,
            'legal_instrument' => 'Lei Orgânica Municipal e LOA 2027',
            'administrative_process' => 'PM-GUA-2027-003',
            'bank_tracking_type' => 'municipal_direct_codes',
            'funding_source_code' => '08',
            'application_code_fixed' => '301',
            'application_code_variable' => '0003',
            'expected_amount' => 95000,
            'received_amount' => 95000,
            'status' => ParliamentaryAmendment::STATUS_ACCOUNTABILITY_PENDING,
            'indicated_at' => '2027-01-20',
            'received_at' => '2027-02-10',
            'communication_deadline' => '2027-02-20',
            'communication_completed_at' => '2027-02-13',
            'execution_deadline' => '2027-06-30',
            'application_deadline' => '2027-07-31',
            'execution_completed_at' => '2027-05-28',
            'accountability_deadline' => '2027-08-30',
            'notes' => 'Emenda demonstrativa com execução simplificada e prestação em preparação.',
        ])->save();

        return $amendment;
    }

    private function executionAndAccountability(Municipality $municipality, ParliamentaryAmendment $amendment, User $manager, User $executive): void
    {
        $stage = ExecutionStage::firstOrNew(['parliamentary_amendment_id' => $amendment->id, 'title' => 'Aquisição e instalação dos equipamentos']);
        $stage->forceFill([
            'municipality_id' => $municipality->id,
            'responsible_user_id' => $executive->id,
            'created_by' => $manager->id,
            'description' => 'Compra, recebimento e instalação dos equipamentos na sala de vacinação.',
            'status' => ExecutionStage::STATUS_COMPLETED,
            'progress_percentage' => 100,
            'planned_amount' => 95000,
            'planned_start_at' => '2027-03-01',
            'planned_end_at' => '2027-05-30',
            'completed_at' => '2027-05-28',
            'sort_order' => 10,
        ])->save();

        $commitment = FinancialCommitment::firstOrNew(['parliamentary_amendment_id' => $amendment->id, 'commitment_number' => 'EMP-GUA-2027-003']);
        $commitment->forceFill([
            'municipality_id' => $municipality->id,
            'execution_stage_id' => $stage->id,
            'created_by' => $manager->id,
            'supplier_name' => 'Fornecedor Demonstrativo de Equipamentos Médicos',
            'supplier_document' => '00.000.000/0001-00',
            'procurement_process' => 'DISP-GUA-2027-003',
            'object_description' => $amendment->object,
            'committed_amount' => 95000,
            'committed_at' => '2027-03-15',
            'status' => FinancialCommitment::STATUS_ACTIVE,
        ])->save();

        DB::table('financial_liquidations')->updateOrInsert(
            ['parliamentary_amendment_id' => $amendment->id, 'liquidation_reference' => 'LIQ-GUA-2027-003'],
            [
                'municipality_id' => $municipality->id,
                'financial_commitment_id' => $commitment->id,
                'created_by' => $manager->id,
                'amount' => 95000,
                'liquidated_at' => '2027-05-28',
                'supporting_document' => 'NF DEMO 003',
                'acceptance_reference' => 'TR DEMO 003',
                'notes' => 'Liquidação demonstrativa vinculada ao recebimento definitivo.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $liquidationId = DB::table('financial_liquidations')
            ->where('parliamentary_amendment_id', $amendment->id)
            ->where('liquidation_reference', 'LIQ-GUA-2027-003')
            ->value('id');

        DB::table('financial_payments')->updateOrInsert(
            ['parliamentary_amendment_id' => $amendment->id, 'payment_reference' => 'PAG-GUA-2027-003'],
            [
                'municipality_id' => $municipality->id,
                'financial_commitment_id' => $commitment->id,
                'financial_liquidation_id' => $liquidationId,
                'created_by' => $manager->id,
                'amount' => 95000,
                'paid_at' => '2027-06-05',
                'notes' => 'Pagamento demonstrativo para fechamento da prestação.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $process = AccountabilityProcess::firstOrNew(['parliamentary_amendment_id' => $amendment->id]);
        $process->forceFill([
            'municipality_id' => $municipality->id,
            'responsible_user_id' => $executive->id,
            'created_by' => $manager->id,
            'status' => AccountabilityProcess::STATUS_PREPARING,
            'due_at' => '2027-08-30',
            'reconciliation_notes' => 'Execução financeira conciliada com empenho, liquidação e pagamento demonstrativos.',
            'submission_notes' => 'Prestação pronta para protocolo final no modo demonstração.',
        ])->save();

        foreach ([
            ['document', 'Plano de trabalho anexado', 'Plano, objeto, meta e valor conferidos.', 10],
            ['financial', 'Execução financeira conciliada', 'Empenho, liquidação e pagamento batem com o valor reservado.', 20],
            ['physical', 'Entrega física evidenciada', 'Recebimento dos equipamentos e registro fotográfico demonstrativo.', 30],
            ['protocol', 'Pacote final revisado', 'Dossiê pronto para protocolo de prestação de contas.', 40],
        ] as [$category, $title, $description, $order]) {
            $requirement = AccountabilityRequirement::firstOrNew(['parliamentary_amendment_id' => $amendment->id, 'title' => $title]);
            $requirement->forceFill([
                'municipality_id' => $municipality->id,
                'accountability_process_id' => $process->id,
                'completed_by' => $manager->id,
                'created_by' => $manager->id,
                'category' => $category,
                'description' => $description,
                'is_required' => true,
                'status' => AccountabilityRequirement::STATUS_COMPLETED,
                'notes' => 'Item demonstrativo concluído.',
                'completed_at' => now()->subDays(2),
                'sort_order' => $order,
            ])->save();
        }

        $type = DocumentType::where('municipality_id', $municipality->id)
            ->where('name', 'Relatório de prestação de contas')
            ->first();
        $path = 'demo/guapiara/prestacao-contas-em-gua-2027-003.txt';
        $content = "Demonstração TrilhaGov - Guapiara\nEmenda: {$amendment->reference}\nObjeto: {$amendment->object}\nValor: R$ 95.000,00\nStatus: prestação em preparação.\n";
        Storage::disk('local')->put($path, $content);

        DB::table('amendment_documents')->updateOrInsert(
            ['parliamentary_amendment_id' => $amendment->id, 'original_name' => 'prestacao-contas-em-gua-2027-003.txt', 'version' => 1],
            [
                'municipality_id' => $municipality->id,
                'document_type_id' => $type?->id,
                'execution_stage_id' => $stage->id,
                'uploaded_by' => $manager->id,
                'uploader_name' => $manager->name,
                'storage_path' => $path,
                'mime_type' => 'text/plain',
                'size_bytes' => strlen($content),
                'notes' => 'Documento demonstrativo para pacote final da prestação.',
                'created_at' => now(),
            ],
        );
    }
}
