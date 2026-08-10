<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\MunicipalRegulatoryProfile;
use App\Models\Municipality;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SecurityPrivacyController extends Controller
{
    public function __invoke(Request $request): View
    {
        /** @var Municipality $municipality */
        $municipality = $request->attributes->get('active_municipality');
        $users = $municipality->users()->get();
        $invitations = $municipality->invitations()->latest()->get();
        $activeProfile = $municipality->regulatoryProfiles()
            ->where('status', MunicipalRegulatoryProfile::STATUS_ACTIVE)
            ->latest('activated_at')
            ->first();
        $auditLogCount = AuditLog::query()->where('municipality_id', $municipality->id)->count();
        $pendingInvitations = $invitations->whereNull('accepted_at')->where('expires_at', '>', now())->count();
        $expiredInvitations = $invitations->whereNull('accepted_at')->where('expires_at', '<=', now())->count();
        $transparencyStatus = $municipality->transparency_enabled ? 'attention' : 'ok';
        $sensitiveUsers = $users->filter(fn (User $user) => in_array($user->pivot->role, [
            User::ROLE_MANAGER,
            User::ROLE_AUDITOR,
        ], true));
        $mfaEnabledCount = $sensitiveUsers->where('mfa_enabled', true)->count();
        $allSensitiveMfa = $sensitiveUsers->isEmpty() || $mfaEnabledCount === $sensitiveUsers->count();
        $mfaDeliveryReady = $this->mfaDeliveryReady();

        $checks = [
            [
                'status' => 'ok',
                'title' => 'Banco protegido pelo backend',
                'description' => 'A conexão com Supabase/Postgres fica no Laravel. O navegador não recebe senha de banco nem chave administrativa.',
            ],
            [
                'status' => 'ok',
                'title' => 'Dados escopados por município',
                'description' => 'Rotas autenticadas exigem município ativo e papel permitido antes de abrir informações operacionais.',
            ],
            [
                'status' => 'ok',
                'title' => 'Convites não usam token salvo em texto puro',
                'description' => 'O convite público valida o token pelo hash SHA-256 e bloqueia tentativas repetidas.',
            ],
            [
                'status' => $municipality->transparency_enabled ? 'attention' : 'ok',
                'title' => 'Portal público de transparência',
                'description' => $municipality->transparency_enabled
                    ? 'O portal público está ativo. Revise se somente dados publicáveis aparecem para consulta externa.'
                    : 'O portal público está desligado. Dados externos não ficam expostos por transparência.',
            ],
            [
                'status' => config('app.debug') ? 'critical' : 'ok',
                'title' => 'Modo debug em produção',
                'description' => config('app.debug')
                    ? 'APP_DEBUG está ativo. Em produção, desligue para não revelar stack trace ou detalhes internos.'
                    : 'APP_DEBUG está desligado, evitando exposição de erros técnicos para usuários.',
            ],
            [
                'status' => $activeProfile?->document_retention_years ? 'ok' : 'attention',
                'title' => 'Retenção documental parametrizada',
                'description' => $activeProfile?->document_retention_years
                    ? 'A norma vigente define retenção documental por '.$activeProfile->document_retention_years.' ano(s), servindo de base para descarte controlado.'
                    : 'Ative uma norma municipal com prazo de retenção para orientar descarte e guarda mínima.',
            ],
            [
                'status' => $allSensitiveMfa && $mfaDeliveryReady ? 'ok' : 'attention',
                'title' => 'MFA para gestores e auditoria',
                'description' => $mfaDeliveryReady
                    ? $mfaEnabledCount.' de '.$sensitiveUsers->count().' usuário(s) sensíveis com segundo fator ativo.'
                    : 'Configure um mailer real antes de ativar MFA em produção.',
            ],
        ];

        return view('security-privacy.index', [
            'municipality' => $municipality,
            'checks' => $checks,
            'okChecks' => collect($checks)->where('status', 'ok')->count(),
            'roleCounts' => $users->countBy(fn (User $user) => $user->pivot->role)->all(),
            'invitations' => [
                'pending' => $pendingInvitations,
                'accepted' => $invitations->whereNotNull('accepted_at')->count(),
                'expired' => $expiredInvitations,
            ],
            'dataInventory' => $this->dataInventory($municipality, $users->count(), $invitations->count(), $auditLogCount),
            'legalBases' => $this->legalBases(),
            'riskMatrix' => $this->riskMatrix($municipality, $pendingInvitations, $expiredInvitations, $auditLogCount, $transparencyStatus),
            'retentionPlan' => $this->retentionPlan($activeProfile),
            'incidentPlaybook' => $this->incidentPlaybook(),
            'currentUserMfaEnabled' => (bool) $request->user()->mfa_enabled,
            'mfaDeliveryReady' => $mfaDeliveryReady,
        ]);
    }

    public function updateMfa(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        if ((bool) $validated['enabled'] && ! $this->mfaDeliveryReady()) {
            return back()->withErrors([
                'mfa' => 'Configure um envio de e-mail real antes de ativar MFA em produção.',
            ]);
        }

        $request->user()->forceFill([
            'mfa_enabled' => (bool) $validated['enabled'],
            'mfa_code_hash' => null,
            'mfa_code_expires_at' => null,
        ])->save();

        return back()->with('status', (bool) $validated['enabled']
            ? 'Verificação em duas etapas ativada para sua conta.'
            : 'Verificação em duas etapas desativada para sua conta.');
    }

    private function mfaDeliveryReady(): bool
    {
        if (config('app.env') !== 'production') {
            return true;
        }

        $mailer = (string) config('mail.default');
        if (in_array($mailer, ['log', 'array'], true)) {
            return false;
        }

        $from = (string) config('mail.from.address');
        if ($from === '' || str_ends_with($from, '.local') || ! filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if ($mailer !== 'smtp') {
            return true;
        }

        $smtp = config('mail.mailers.smtp', []);

        return filled($smtp['host'] ?? null)
            && filled($smtp['port'] ?? null)
            && filled($smtp['username'] ?? null)
            && filled($smtp['password'] ?? null);
    }

    /** @return array<int, array{area: string, data: string, count: string, purpose: string, exposure: string}> */
    private function dataInventory(Municipality $municipality, int $userCount, int $invitationCount, int $auditLogCount): array
    {
        return [
            [
                'area' => 'Acessos',
                'data' => 'Usuários, papéis, convites e identidade legislativa',
                'count' => $userCount.' usuário(s) / '.$invitationCount.' convite(s)',
                'purpose' => 'Autenticação, autorização e segregação de responsabilidades.',
                'exposure' => 'Somente gestor vê a administração de usuários.',
            ],
            [
                'area' => 'Emendas',
                'data' => 'Autores, beneficiários, objetos, valores e prazos',
                'count' => $municipality->amendments()->count().' emenda(s)',
                'purpose' => 'Gestão municipal, execução, controle interno e prestação de contas.',
                'exposure' => 'Interno; transparência pública somente quando habilitada.',
            ],
            [
                'area' => 'Câmara',
                'data' => 'Propostas legislativas e acompanhamento do vereador',
                'count' => $municipality->legislativeProposals()->count().' proposta(s)',
                'purpose' => 'Registrar indicações, cotas, saúde e protocolo ao Executivo.',
                'exposure' => 'Vereador vê própria carteira; Executivo vê fila municipal.',
            ],
            [
                'area' => 'Documentos',
                'data' => 'Anexos, evidências, pareceres, dossiês e hashes',
                'count' => $municipality->documents()->count().' documento(s)',
                'purpose' => 'Prova de conformidade, auditoria e resposta ao controle externo.',
                'exposure' => 'Privado no storage; download passa por município ativo.',
            ],
            [
                'area' => 'Auditoria',
                'data' => 'Ações, IP, agente de usuário e trilhas de alteração',
                'count' => $auditLogCount.' evento(s)',
                'purpose' => 'Rastreabilidade, responsabilização e investigação de incidente.',
                'exposure' => 'Consulta operacional restrita e imutável.',
            ],
        ];
    }

    /** @return array<int, array{title: string, description: string}> */
    private function legalBases(): array
    {
        return [
            ['title' => 'Obrigação legal e regulatória', 'description' => 'Execução de emendas, prestação de contas, controle interno e atendimento aos órgãos de controle.'],
            ['title' => 'Execução de políticas públicas', 'description' => 'Acompanhamento de entregas municipais financiadas por emendas e indicações legislativas.'],
            ['title' => 'Legítimo interesse administrativo', 'description' => 'Segurança, auditoria, prevenção a fraude, suporte e continuidade do serviço público.'],
        ];
    }

    /** @return array<int, array{status: string, title: string, signal: string, action: string}> */
    private function riskMatrix(Municipality $municipality, int $pendingInvitations, int $expiredInvitations, int $auditLogCount, string $transparencyStatus): array
    {
        return [
            [
                'status' => $pendingInvitations > 0 ? 'attention' : 'ok',
                'title' => 'Convites pendentes',
                'signal' => $pendingInvitations.' convite(s) ainda podem ser aceitos.',
                'action' => 'Revogue convites antigos e reenvie somente para e-mails confirmados.',
            ],
            [
                'status' => $expiredInvitations > 0 ? 'attention' : 'ok',
                'title' => 'Convites expirados',
                'signal' => $expiredInvitations.' convite(s) expirado(s) no histórico.',
                'action' => 'Mantenha expirados como trilha, mas cancele os que não deveriam mais existir.',
            ],
            [
                'status' => $transparencyStatus,
                'title' => 'Transparência pública',
                'signal' => $municipality->transparency_enabled ? 'Portal público habilitado.' : 'Portal público desabilitado.',
                'action' => 'Antes de publicar, confira se dados pessoais e documentos internos não aparecem.',
            ],
            [
                'status' => $auditLogCount > 0 ? 'ok' : 'attention',
                'title' => 'Trilha de auditoria',
                'signal' => $auditLogCount.' evento(s) registrado(s).',
                'action' => 'Toda ação sensível deve manter ator, data, IP e alteração realizada.',
            ],
        ];
    }

    /** @return array<int, array{item: string, rule: string}> */
    private function retentionPlan(?MunicipalRegulatoryProfile $profile): array
    {
        $years = $profile?->document_retention_years;
        $retention = $years ? $years.' ano(s)' : 'definir na norma municipal';

        return [
            ['item' => 'Documentos de emendas', 'rule' => 'Guardar por '.$retention.' ou prazo superior exigido pelo controle externo.'],
            ['item' => 'Convites e usuários', 'rule' => 'Manter histórico mínimo para auditoria; remover acesso quando não houver vínculo.'],
            ['item' => 'Logs de auditoria', 'rule' => 'Preservar trilha imutável para investigação e responsabilização.'],
            ['item' => 'Dossiês e relatórios', 'rule' => 'Preservar versões emitidas com hash e manifesto dos anexos incluídos.'],
        ];
    }

    /** @return array<int, array{step: string, title: string, description: string}> */
    private function incidentPlaybook(): array
    {
        return [
            ['step' => '1', 'title' => 'Conter', 'description' => 'Revogar acesso suspeito, trocar segredos e pausar publicação externa se necessário.'],
            ['step' => '2', 'title' => 'Preservar prova', 'description' => 'Exportar logs, usuário, IP, rota afetada, horário e registros consultados.'],
            ['step' => '3', 'title' => 'Classificar', 'description' => 'Identificar dado pessoal, volume, titulares afetados e risco ao município.'],
            ['step' => '4', 'title' => 'Comunicar', 'description' => 'Acionar responsável municipal, jurídico/controladoria e avaliar comunicação a ANPD/titulares.'],
            ['step' => '5', 'title' => 'Corrigir', 'description' => 'Fechar brecha, registrar decisão, revisar permissão e atualizar rotina operacional.'],
        ];
    }
}
