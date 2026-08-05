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
                'description' => 'A conexao com Supabase/Postgres fica no Laravel. O navegador nao recebe senha de banco nem chave administrativa.',
            ],
            [
                'status' => 'ok',
                'title' => 'Dados escopados por municipio',
                'description' => 'Rotas autenticadas exigem municipio ativo e papel permitido antes de abrir informacoes operacionais.',
            ],
            [
                'status' => 'ok',
                'title' => 'Convites nao usam token salvo em texto puro',
                'description' => 'O convite publico valida o token pelo hash SHA-256 e bloqueia tentativas repetidas.',
            ],
            [
                'status' => $municipality->transparency_enabled ? 'attention' : 'ok',
                'title' => 'Portal publico de transparencia',
                'description' => $municipality->transparency_enabled
                    ? 'O portal publico esta ativo. Revise se somente dados publicaveis aparecem para consulta externa.'
                    : 'O portal publico esta desligado. Dados externos nao ficam expostos por transparencia.',
            ],
            [
                'status' => config('app.debug') ? 'critical' : 'ok',
                'title' => 'Modo debug em producao',
                'description' => config('app.debug')
                    ? 'APP_DEBUG esta ativo. Em producao, desligue para nao revelar stack trace ou detalhes internos.'
                    : 'APP_DEBUG esta desligado, evitando exposicao de erros tecnicos para usuarios.',
            ],
            [
                'status' => $activeProfile?->document_retention_years ? 'ok' : 'attention',
                'title' => 'Retencao documental parametrizada',
                'description' => $activeProfile?->document_retention_years
                    ? 'A norma vigente define retencao documental por '.$activeProfile->document_retention_years.' ano(s), servindo de base para descarte controlado.'
                    : 'Ative uma norma municipal com prazo de retencao para orientar descarte e guarda minima.',
            ],
            [
                'status' => $allSensitiveMfa && $mfaDeliveryReady ? 'ok' : 'attention',
                'title' => 'MFA para gestores e auditoria',
                'description' => $mfaDeliveryReady
                    ? $mfaEnabledCount.' de '.$sensitiveUsers->count().' usuario(s) sensiveis com segundo fator ativo.'
                    : 'Configure um mailer real antes de ativar MFA em producao.',
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
                'mfa' => 'Configure um envio de e-mail real antes de ativar MFA em producao.',
            ]);
        }

        $request->user()->forceFill([
            'mfa_enabled' => (bool) $validated['enabled'],
            'mfa_code_hash' => null,
            'mfa_code_expires_at' => null,
        ])->save();

        return back()->with('status', (bool) $validated['enabled']
            ? 'Verificacao em duas etapas ativada para sua conta.'
            : 'Verificacao em duas etapas desativada para sua conta.');
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
                'data' => 'Usuarios, papeis, convites e identidade legislativa',
                'count' => $userCount.' usuario(s) / '.$invitationCount.' convite(s)',
                'purpose' => 'Autenticacao, autorizacao e segregacao de responsabilidades.',
                'exposure' => 'Somente gestor ve a administracao de usuarios.',
            ],
            [
                'area' => 'Emendas',
                'data' => 'Autores, beneficiarios, objetos, valores e prazos',
                'count' => $municipality->amendments()->count().' emenda(s)',
                'purpose' => 'Gestao municipal, execucao, controle interno e prestacao de contas.',
                'exposure' => 'Interno; transparencia publica somente quando habilitada.',
            ],
            [
                'area' => 'Camara',
                'data' => 'Propostas legislativas e acompanhamento do vereador',
                'count' => $municipality->legislativeProposals()->count().' proposta(s)',
                'purpose' => 'Registrar indicacoes, cotas, saude e protocolo ao Executivo.',
                'exposure' => 'Vereador ve propria carteira; Executivo ve fila municipal.',
            ],
            [
                'area' => 'Documentos',
                'data' => 'Anexos, evidencias, pareceres, dossies e hashes',
                'count' => $municipality->documents()->count().' documento(s)',
                'purpose' => 'Prova de conformidade, auditoria e resposta ao controle externo.',
                'exposure' => 'Privado no storage; download passa por municipio ativo.',
            ],
            [
                'area' => 'Auditoria',
                'data' => 'Acoes, IP, agente de usuario e trilhas de alteracao',
                'count' => $auditLogCount.' evento(s)',
                'purpose' => 'Rastreabilidade, responsabilizacao e investigacao de incidente.',
                'exposure' => 'Consulta operacional restrita e imutavel.',
            ],
        ];
    }

    /** @return array<int, array{title: string, description: string}> */
    private function legalBases(): array
    {
        return [
            ['title' => 'Obrigacao legal e regulatoria', 'description' => 'Execucao de emendas, prestacao de contas, controle interno e atendimento aos orgaos de controle.'],
            ['title' => 'Execucao de politicas publicas', 'description' => 'Acompanhamento de entregas municipais financiadas por emendas e indicacoes legislativas.'],
            ['title' => 'Legitimo interesse administrativo', 'description' => 'Seguranca, auditoria, prevencao a fraude, suporte e continuidade do servico publico.'],
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
                'signal' => $expiredInvitations.' convite(s) expirado(s) no historico.',
                'action' => 'Mantenha expirados como trilha, mas cancele os que nao deveriam mais existir.',
            ],
            [
                'status' => $transparencyStatus,
                'title' => 'Transparencia publica',
                'signal' => $municipality->transparency_enabled ? 'Portal publico habilitado.' : 'Portal publico desabilitado.',
                'action' => 'Antes de publicar, confira se dados pessoais e documentos internos nao aparecem.',
            ],
            [
                'status' => $auditLogCount > 0 ? 'ok' : 'attention',
                'title' => 'Trilha de auditoria',
                'signal' => $auditLogCount.' evento(s) registrado(s).',
                'action' => 'Toda acao sensivel deve manter ator, data, IP e alteracao realizada.',
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
            ['item' => 'Convites e usuarios', 'rule' => 'Manter historico minimo para auditoria; remover acesso quando nao houver vinculo.'],
            ['item' => 'Logs de auditoria', 'rule' => 'Preservar trilha imutavel para investigacao e responsabilizacao.'],
            ['item' => 'Dossies e relatorios', 'rule' => 'Preservar versoes emitidas com hash e manifesto dos anexos incluidos.'],
        ];
    }

    /** @return array<int, array{step: string, title: string, description: string}> */
    private function incidentPlaybook(): array
    {
        return [
            ['step' => '1', 'title' => 'Conter', 'description' => 'Revogar acesso suspeito, trocar segredos e pausar publicacao externa se necessario.'],
            ['step' => '2', 'title' => 'Preservar prova', 'description' => 'Exportar logs, usuario, IP, rota afetada, horario e registros consultados.'],
            ['step' => '3', 'title' => 'Classificar', 'description' => 'Identificar dado pessoal, volume, titulares afetados e risco ao municipio.'],
            ['step' => '4', 'title' => 'Comunicar', 'description' => 'Acionar responsavel municipal, juridico/controladoria e avaliar comunicacao a ANPD/titulares.'],
            ['step' => '5', 'title' => 'Corrigir', 'description' => 'Fechar brecha, registrar decisao, revisar permissao e atualizar rotina operacional.'],
        ];
    }
}
