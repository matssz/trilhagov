<?php

namespace App\Services;

use App\Models\AccountabilityProcess;
use App\Models\AccountabilityRequirement;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountabilityService
{
    /** @return array<int, array<string, mixed>> */
    public function defaultRequirements(): array
    {
        return [
            [
                'category' => 'document',
                'title' => 'Relatório de cumprimento do objeto',
                'description' => 'Relatório consolidado das entregas realizadas e dos resultados alcançados.',
                'is_required' => true,
                'sort_order' => 10,
            ],
            [
                'category' => 'financial',
                'title' => 'Extrato da conta específica',
                'description' => 'Extrato que permita conferir entradas, pagamentos e saldo do recurso.',
                'is_required' => true,
                'sort_order' => 20,
            ],
            [
                'category' => 'financial',
                'title' => 'Comprovantes de despesas e pagamentos',
                'description' => 'Documentos que sustentam os empenhos e pagamentos informados.',
                'is_required' => true,
                'sort_order' => 30,
            ],
            [
                'category' => 'physical',
                'title' => 'Comprovação das entregas',
                'description' => 'Termos de recebimento, registros fotográficos ou documentos equivalentes.',
                'is_required' => true,
                'sort_order' => 40,
            ],
            [
                'category' => 'financial',
                'title' => 'Comprovante de devolução de saldo',
                'description' => 'Marque como não aplicável quando não houver valor a devolver.',
                'is_required' => false,
                'sort_order' => 50,
            ],
        ];
    }

    public function seedRequirements(AccountabilityProcess $process, User $creator): void
    {
        foreach ($this->defaultRequirements() as $requirement) {
            $process->requirements()->create([
                ...$requirement,
                'municipality_id' => $process->municipality_id,
                'parliamentary_amendment_id' => $process->parliamentary_amendment_id,
                'created_by' => $creator->id,
                'status' => AccountabilityRequirement::STATUS_PENDING,
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function guide(ParliamentaryAmendment $amendment, ?AccountabilityProcess $process, ?array $readiness, ?string $role = null): array
    {
        $financial = $process ? $this->financialSummary($amendment, $process) : $this->financialSummary($amendment);
        $physicalPercentage = $amendment->physicalExecutionPercentage();
        $evidenceCount = $amendment->documents->whereNotNull('execution_stage_id')->count();
        $hasProcess = $process !== null;
        $checklistReady = $process
            ? $process->requirements->where('is_required', true)->every(fn ($requirement) => in_array($requirement->status, [
                AccountabilityRequirement::STATUS_COMPLETED,
                AccountabilityRequirement::STATUS_NOT_APPLICABLE,
            ], true))
            : false;
        $financialReady = $amendment->received_amount !== null && abs($financial['unreconciled']) <= 0.01;
        $ready = (bool) ($readiness['ready'] ?? false);
        $openDiligences = $process?->diligences->where('status', 'open')->count() ?? 0;
        $score = (int) ($readiness['score'] ?? 0);
        $closingTone = $ready ? 'success' : ($hasProcess && $score >= 60 ? 'warning' : 'neutral');
        $closingLabel = $ready ? 'Pronta para protocolo' : ($hasProcess ? 'Fechamento em andamento' : 'Prestação não iniciada');
        $physicalComplete = $physicalPercentage >= 100;
        $hasEvidence = $evidenceCount > 0;
        $protocolLabel = $process?->protocol_number ?: 'Aguardando protocolo';
        $submittedLabel = $process?->submitted_at?->format('d/m/Y') ?? 'Não enviado';
        $approvedLabel = $process?->approved_at?->format('d/m/Y') ?? 'Não aprovado';
        $statusLabel = $process?->statusLabel() ?? 'Não iniciada';
        $responsibleLabel = $process?->responsibleUser?->name ?? $amendment->responsibleUser?->name ?? 'Não definido';
        $deadlineLabel = $process?->due_at?->format('d/m/Y') ?? $amendment->accountability_deadline?->format('d/m/Y') ?? 'Não definido';
        $receiptSeal = $process?->approved_at
            ? 'Prestação aprovada e arquivável'
            : ($process?->submitted_at
                ? 'Prestação protocolada'
                : ($ready ? 'Pronta para apresentar' : 'Em preparação'));
        $timelineProtocolDetail = $process?->protocol_number
            ? 'Protocolo '.$process->protocol_number.' em '.$submittedLabel
            : 'Informe protocolo e data de envio quando a prestação for apresentada.';

        $blockerActions = collect();

        if (! $hasProcess) {
            $blockerActions->push([
                'icon' => 'clipboard-list',
                'title' => 'Abrir a prestação',
                'description' => 'Crie o processo para o sistema montar checklist, responsável e conferência automática.',
                'href' => '#iniciar-prestacao',
                'label' => 'Iniciar agora',
                'tone' => 'warning',
            ]);
        } else {
            if (! $checklistReady) {
                $blockerActions->push([
                    'icon' => 'wand-sparkles',
                    'title' => 'Checklist pendente',
                    'description' => 'Rode a pré-conferência para aproveitar documentos, pagamentos e execução já cadastrados.',
                    'href' => '#assistente-prestacao',
                    'label' => 'Pre-conferir',
                    'tone' => 'warning',
                ]);
            }

            if (! $physicalComplete) {
                $blockerActions->push([
                    'icon' => 'route',
                    'title' => 'Execução incompleta',
                    'description' => 'Conclua as etapas físicas antes de enviar a prestação final.',
                    'href' => route('emendas.execution', $amendment),
                    'label' => 'Abrir execução',
                    'tone' => 'danger',
                ]);
            }

            if (! $hasEvidence) {
                $blockerActions->push([
                    'icon' => 'paperclip',
                    'title' => 'Evidência não vinculada',
                    'description' => 'Anexe ou vincule documento de entrega em uma etapa de execução.',
                    'href' => route('emendas.execution', $amendment).'#evidence',
                    'label' => 'Anexar evidÃªncia',
                    'tone' => 'warning',
                ]);
            }

            if ($amendment->received_amount === null) {
                $blockerActions->push([
                    'icon' => 'banknote',
                    'title' => 'Valor recebido ausente',
                    'description' => 'Informe o recebimento do recurso para fechar a conciliação financeira.',
                    'href' => route('emendas.edit', $amendment),
                    'label' => 'Editar emenda',
                    'tone' => 'danger',
                ]);
            } elseif (! $financialReady) {
                $blockerActions->push([
                    'icon' => 'scale',
                    'title' => 'Saldo sem conciliação',
                    'description' => 'Registre pagamento ou devolução para zerar a diferença financeira.',
                    'href' => '#process',
                    'label' => 'Conciliar saldo',
                    'tone' => 'danger',
                ]);
            }

            if ($openDiligences > 0) {
                $blockerActions->push([
                    'icon' => 'message-square-warning',
                    'title' => 'Diligência aberta',
                    'description' => 'Responda a solicitação, registre protocolo e deixe o processo apto para envio.',
                    'href' => '#diligences',
                    'label' => 'Responder',
                    'tone' => 'warning',
                ]);
            }
        }

        $next = [
            'icon' => 'clipboard-list',
            'title' => 'Iniciar prestação simplificada',
            'description' => 'Abra o processo para o sistema montar o checklist e ler execução, pagamentos e evidências já registrados.',
            'href' => '#iniciar-prestacao',
            'label' => 'Iniciar processo',
        ];

        if ($hasProcess && ! $checklistReady) {
            $next = [
                'icon' => 'wand-sparkles',
                'title' => 'Pre-conferir checklist',
                'description' => 'Use os dados da execução para resolver automaticamente itens que já possuem lastro no sistema.',
                'href' => '#assistente-prestacao',
                'label' => 'Pre-conferir agora',
            ];
        } elseif ($hasProcess && ! $financialReady) {
            $next = [
                'icon' => 'scale',
                'title' => 'Conciliar saldo',
                'description' => 'Ajuste pagamentos ou devolução para fechar recebido, pago e saldo devolvido.',
                'href' => '#reconciliation',
                'label' => 'Ver conciliação',
            ];
        } elseif ($hasProcess && ! $ready) {
            $next = [
                'icon' => 'list-checks',
                'title' => 'Resolver pendências finais',
                'description' => 'Finalize execução física, evidências ou diligências antes do protocolo.',
                'href' => '#requirements',
                'label' => 'Ver pendências',
            ];
        } elseif ($hasProcess) {
            $next = [
                'icon' => 'send',
                'title' => 'Enviar prestação de contas',
                'description' => 'A base está pronta para informar protocolo, data de envio e gerar o dossiê.',
                'href' => '#process',
                'label' => 'Informar protocolo',
            ];
        }

        return [
            'next' => $next,
            'profile' => $this->profileGuidance($role, $process, $ready),
            'command' => [
                [
                    'icon' => 'wand-sparkles',
                    'label' => 'Preparar processo',
                    'metric' => $hasProcess ? 'Aberto' : 'Não iniciado',
                    'description' => $hasProcess
                        ? 'Checklist e responsável já existem para esta emenda.'
                        : 'Cria a prestação e preenche o que o sistema já consegue validar.',
                    'href' => $hasProcess ? '#process' : '#iniciar-prestacao',
                    'cta' => $hasProcess ? 'Ver processo' : 'Preparar agora',
                    'tone' => $hasProcess ? 'success' : 'warning',
                ],
                [
                    'icon' => 'list-checks',
                    'label' => 'Conferir checklist',
                    'metric' => $process ? ($readiness['required_resolved'] ?? 0).'/'.($readiness['required_total'] ?? 0) : '0/0',
                    'description' => $checklistReady
                        ? 'Itens obrigatórios resolvidos ou marcados como não aplicáveis.'
                        : 'Use a pré-conferência para aproveitar dados de execução e documentos.',
                    'href' => $process && ! $checklistReady ? '#assistente-prestacao' : '#requirements',
                    'cta' => $checklistReady ? 'Ver checklist' : 'Pre-conferir',
                    'tone' => $checklistReady ? 'success' : 'warning',
                ],
                [
                    'icon' => 'scale',
                    'label' => 'Conciliar saldo',
                    'metric' => 'R$ '.number_format($financial['unreconciled'], 2, ',', '.'),
                    'description' => $financialReady
                        ? 'Recebido, pago e devolvido estão fechados.'
                        : 'Ajuste pagamento ou devolução antes de registrar envio.',
                    'href' => '#reconciliation',
                    'cta' => 'Abrir conciliação',
                    'tone' => $financialReady ? 'success' : 'danger',
                ],
                [
                    'icon' => 'send',
                    'label' => 'Enviar prestação',
                    'metric' => $ready ? 'Liberada' : $score.'%',
                    'description' => $ready
                        ? 'Informe protocolo e mantenha o dossiê pronto para auditoria.'
                        : 'Finalize pendências antes de protocolar.',
                    'href' => $ready ? '#envio-prestacao' : '#requirements',
                    'cta' => $ready ? 'Informar protocolo' : 'Ver pendências',
                    'tone' => $ready ? 'success' : 'warning',
                ],
                [
                    'icon' => 'package-check',
                    'label' => 'Dossiê',
                    'metric' => $hasProcess ? 'Disponível' : 'Aguardando',
                    'description' => 'Baixe PDF executivo e pacote de anexos quando o processo existir.',
                    'href' => $hasProcess ? '#dossie-prestacao' : '#iniciar-prestacao',
                    'cta' => $hasProcess ? 'Baixar arquivos' : 'Abrir processo',
                    'tone' => $hasProcess ? 'primary' : 'warning',
                ],
            ],
            'closing' => [
                'score' => $score,
                'label' => $closingLabel,
                'tone' => $closingTone,
                'description' => 'Fechamento, checklist e dossiê em uma trilha.',
                'facts' => [
                    ['label' => 'Checklist', 'value' => $process ? ($readiness['required_resolved'] ?? 0).'/'.($readiness['required_total'] ?? 0) : 'Não iniciado'],
                    ['label' => 'Saldo', 'value' => 'R$ '.number_format($financial['unreconciled'], 2, ',', '.')],
                    ['label' => 'Diligências', 'value' => $openDiligences.' aberta(s)'],
                ],
            ],
            'blockerActions' => $blockerActions->take(4)->values(),
            'flow' => [
                ['label' => 'Abrir processo', 'description' => 'Cria checklist e responsável.', 'done' => $hasProcess, 'href' => $hasProcess ? '#process' : '#iniciar-prestacao'],
                ['label' => 'Pré-conferir', 'description' => 'Resolve itens com dados já registrados.', 'done' => $checklistReady, 'href' => '#assistente-prestacao'],
                ['label' => 'Conciliar saldo', 'description' => 'Fecha recebido, pago e devolvido.', 'done' => $financialReady, 'href' => '#reconciliation'],
                ['label' => 'Resolver pendências', 'description' => 'Checklist, evidências e diligências.', 'done' => $ready, 'href' => '#requirements'],
                ['label' => 'Gerar dossiê', 'description' => 'Baixa PDF e pacote de anexos.', 'done' => $ready, 'href' => '#dossie-prestacao'],
            ],
            'actions' => [
                [
                    'key' => 'quick-check',
                    'icon' => 'wand-sparkles',
                    'label' => 'Pre-conferir agora',
                    'description' => 'Marca automaticamente checklist, saldo e evidências que já possuem base no sistema.',
                    'href' => '#assistente-prestacao',
                    'enabled' => $hasProcess && ! $checklistReady,
                ],
                [
                    'key' => 'execution',
                    'icon' => 'route',
                    'label' => $physicalComplete && $hasEvidence ? 'Revisar execução' : 'Completar execução',
                    'description' => $physicalComplete && $hasEvidence
                        ? 'Confira etapas, pagamentos e documentos usados na prestação.'
                        : 'Conclua etapas físicas, pagamentos e evidências antes de enviar.',
                    'href' => route('emendas.execution', $amendment),
                    'enabled' => $hasProcess && (! $physicalComplete || ! $hasEvidence),
                ],
                [
                    'key' => 'reconciliation',
                    'icon' => 'scale',
                    'label' => 'Conciliar saldo',
                    'description' => 'Ajuste valor devolvido, referência e observações quando houver diferença financeira.',
                    'href' => '#process',
                    'enabled' => $hasProcess && ! $financialReady,
                ],
                [
                    'key' => 'diligences',
                    'icon' => 'message-square-warning',
                    'label' => 'Resolver diligências',
                    'description' => 'Responda pendências abertas e registre protocolo de retorno quando existir.',
                    'href' => '#diligences',
                    'enabled' => $hasProcess && $openDiligences > 0,
                ],
                [
                    'key' => 'submit',
                    'icon' => 'send',
                    'label' => 'Registrar envio',
                    'description' => 'Informe data e protocolo quando a prestação estiver pronta.',
                    'href' => '#envio-prestacao',
                    'enabled' => $ready,
                ],
                [
                    'key' => 'dossier',
                    'icon' => 'package-check',
                    'label' => 'Baixar dossiê',
                    'description' => 'Gere PDF executivo e pacote com anexos para auditoria ou arquivo municipal.',
                    'href' => '#dossie-prestacao',
                    'enabled' => $hasProcess,
                ],
            ],
            'steps' => [
                ['label' => 'Processo aberto', 'description' => 'Checklist municipal criado.', 'done' => $hasProcess],
                ['label' => 'Execução concluída', 'description' => 'Etapas físicas em 100%.', 'done' => $physicalComplete],
                ['label' => 'Financeiro conciliado', 'description' => 'Recebido, pago e devolvido fecham.', 'done' => $financialReady],
                ['label' => 'Evidências vinculadas', 'description' => 'Documentos ligados às entregas.', 'done' => $hasEvidence],
                ['label' => 'Pronta para envio', 'description' => 'Sem bloqueios para protocolo.', 'done' => $ready],
            ],
            'summary' => [
                ['label' => 'Execução física', 'value' => $physicalPercentage.'%'],
                ['label' => 'Evidências', 'value' => $evidenceCount.' documento(s)'],
                ['label' => 'Saldo sem conciliação', 'value' => 'R$ '.number_format($financial['unreconciled'], 2, ',', '.')],
                ['label' => 'Checklist', 'value' => $process ? ($readiness['required_resolved'] ?? 0).'/'.($readiness['required_total'] ?? 0) : 'Não iniciado'],
            ],
            'finalPackage' => [
                'ready' => $ready,
                'title' => $ready ? 'Prestação final pronta' : 'Prestação final em preparação',
                'description' => $ready
                    ? 'O processo já possui base para protocolo, PDF executivo e pacote de documentos.'
                    : 'O sistema mostra exatamente o que falta antes do envio final ao controle interno ou externo.',
                'checks' => [
                    ['label' => 'Processo aberto', 'done' => $hasProcess, 'detail' => $hasProcess ? 'Prestação criada' : 'Inicie o processo'],
                    ['label' => 'Checklist resolvido', 'done' => $checklistReady, 'detail' => $process ? (($readiness['required_resolved'] ?? 0).'/'.($readiness['required_total'] ?? 0).' itens') : 'Não iniciado'],
                    ['label' => 'Financeiro conciliado', 'done' => $financialReady, 'detail' => 'Diferença R$ '.number_format($financial['unreconciled'], 2, ',', '.')],
                    ['label' => 'Evidências anexadas', 'done' => $hasEvidence, 'detail' => $evidenceCount.' documento(s)'],
                    ['label' => 'Sem bloqueios', 'done' => $ready, 'detail' => $ready ? 'Pronto para protocolo' : (($readiness['blockers'] ?? collect())->first() ?? 'Aguardando preparo')],
                ],
            ],
            'finalReceipt' => [
                'seal' => $receiptSeal,
                'protocol' => $protocolLabel,
                'status' => $statusLabel,
                'submitted_at' => $submittedLabel,
                'approved_at' => $approvedLabel,
                'responsible' => $responsibleLabel,
                'deadline' => $deadlineLabel,
                'readiness' => $score.'%',
            ],
            'finalTimeline' => [
                [
                    'title' => 'Recurso recebido',
                    'detail' => $amendment->received_at
                        ? 'Recebido em '.$amendment->received_at->format('d/m/Y').' no valor de R$ '.number_format((float) $amendment->received_amount, 2, ',', '.')
                        : 'Informe o recebimento para fechar a conciliação.',
                    'done' => $amendment->received_at !== null,
                ],
                [
                    'title' => 'Execução comprovada',
                    'detail' => $physicalPercentage.'% de execução física e '.$evidenceCount.' evidência(s) vinculada(s).',
                    'done' => $physicalComplete && $hasEvidence,
                ],
                [
                    'title' => 'Checklist final',
                    'detail' => $process ? (($readiness['required_resolved'] ?? 0).'/'.($readiness['required_total'] ?? 0).' item(ns) obrigatório(s) resolvido(s).') : 'Abra a prestação para montar o checklist.',
                    'done' => $checklistReady,
                ],
                [
                    'title' => 'Protocolo da prestação',
                    'detail' => $timelineProtocolDetail,
                    'done' => $process?->submitted_at !== null,
                ],
                [
                    'title' => 'Arquivo para controle',
                    'detail' => $process?->approved_at
                        ? 'Aprovada em '.$approvedLabel.'.'
                        : ($ready ? 'PDF executivo e pacote de auditoria prontos para apresentação.' : 'Finalize os bloqueios para gerar uma entrega completa.'),
                    'done' => $process?->approved_at !== null || $ready,
                ],
            ],
            'finalCommand' => [
                'tone' => $process?->approved_at
                    ? 'success'
                    : ($ready ? 'primary' : 'warning'),
                'title' => $process?->approved_at
                    ? 'Prestação encerrada para consulta'
                    : ($ready ? 'Pronta para protocolo e arquivo' : 'Fechamento ainda precisa de decisão'),
                'description' => $process?->approved_at
                    ? 'O pacote final permanece disponível para auditoria, Câmara e controle interno.'
                    : ($ready
                        ? 'Registre o protocolo, baixe o pacote e arquive a prestação quando houver aprovação final.'
                        : 'Use a próxima ação sugerida para destravar checklist, execução, evidências ou saldo.'),
                'primary' => [
                    'icon' => $process?->approved_at ? 'package-check' : ($ready ? 'send' : $next['icon']),
                    'label' => $process?->approved_at ? 'Baixar pacote' : ($ready ? 'Protocolar agora' : $next['label']),
                    'href' => $process?->approved_at ? '#dossie-prestacao' : ($ready ? '#envio-prestacao' : $next['href']),
                ],
                'secondary' => collect([
                    ['icon' => 'file-text', 'label' => 'PDF executivo', 'href' => '#dossie-prestacao', 'enabled' => $hasProcess],
                    ['icon' => 'package-check', 'label' => 'Pacote de anexos', 'href' => '#dossie-prestacao', 'enabled' => $hasProcess],
                    ['icon' => 'route', 'label' => 'Execução', 'href' => route('emendas.execution', $amendment), 'enabled' => true],
                    ['icon' => 'list-checks', 'label' => 'Checklist', 'href' => $hasProcess ? '#requirements' : '#iniciar-prestacao', 'enabled' => true],
                ])->filter(fn (array $action) => $action['enabled'])->values()->all(),
                'facts' => [
                    ['label' => 'Protocolo', 'value' => $protocolLabel],
                    ['label' => 'Dossiê', 'value' => $hasProcess ? 'Disponível' : 'Aguardando'],
                    ['label' => 'Evidências', 'value' => $evidenceCount.' vinculada(s)'],
                    ['label' => 'Saldo', 'value' => 'R$ '.number_format($financial['unreconciled'], 2, ',', '.')],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function profileGuidance(?string $role, ?AccountabilityProcess $process, bool $ready): array
    {
        $role ??= User::ROLE_VIEWER;

        $copy = match ($role) {
            User::ROLE_MANAGER => [
                'label' => 'Perfil gestor',
                'title' => $ready ? 'Sua decisão agora é protocolar e arquivar' : 'Sua decisão agora é fechar as pendências',
                'description' => 'Use a pré-conferência, concilie saldo e registre protocolo quando o processo estiver completo.',
                'icon' => 'shield-check',
                'tone' => 'primary',
            ],
            User::ROLE_EDITOR => [
                'label' => 'Perfil operacional',
                'title' => 'Prepare a prestação para decisão final',
                'description' => 'Atualize checklist, diligências, pagamentos e evidências para o gestor protocolar.',
                'icon' => 'clipboard-pen',
                'tone' => 'warning',
            ],
            User::ROLE_AUDITOR => [
                'label' => 'Modo auditoria',
                'title' => 'Consulta orientada por evidências',
                'description' => 'Confira protocolo, checklist, dossiê, conciliação e linha do tempo sem alterar registros.',
                'icon' => 'search-check',
                'tone' => 'neutral',
            ],
            default => [
                'label' => 'Modo consulta',
                'title' => 'Acompanhe o fechamento sem editar',
                'description' => 'A tela mostra pendências, documentos, diligências e status de envio para leitura.',
                'icon' => 'eye',
                'tone' => 'neutral',
            ],
        };

        return [
            ...$copy,
            'items' => [
                ['label' => 'Pode editar', 'value' => in_array($role, [User::ROLE_MANAGER, User::ROLE_EDITOR], true) ? 'Sim' : 'Não'],
                ['label' => 'Processo', 'value' => $process ? $process->statusLabel() : 'Não iniciado'],
                ['label' => 'Próxima decisão', 'value' => $ready ? 'Protocolo final' : 'Resolver pendências'],
            ],
        ];
    }

    /** @return array{updated: int, completed: int, not_applicable: int} */
    public function quickCheck(AccountabilityProcess $process, ParliamentaryAmendment $amendment, User $user): array
    {
        $process->loadMissing('requirements');
        $amendment->loadMissing('executionStages', 'financialCommitments.payments', 'documents.documentType');

        $financial = $this->financialSummary($amendment, $process);
        $physicalComplete = $amendment->executionStages->isNotEmpty()
            && $amendment->executionStages->every(fn ($stage) => $stage->status === 'completed' && $stage->progress_percentage === 100);
        $evidence = $amendment->documents->whereNotNull('execution_stage_id')->first();
        $anyDocument = $amendment->documents->first();
        $updated = 0;
        $completed = 0;
        $notApplicable = 0;

        foreach ($process->requirements as $requirement) {
            if ($requirement->status !== AccountabilityRequirement::STATUS_PENDING) {
                continue;
            }

            $data = null;
            $title = (string) Str::of($requirement->title)->ascii()->lower();

            if (str_contains($title, 'cumprimento do objeto') && $physicalComplete && $evidence) {
                $data = $this->resolvedRequirement($user, AccountabilityRequirement::STATUS_COMPLETED, $evidence->id, 'Execução física concluída e evidência de entrega localizada automaticamente.');
            } elseif (str_contains($title, 'comprovacao') && $evidence) {
                $data = $this->resolvedRequirement($user, AccountabilityRequirement::STATUS_COMPLETED, $evidence->id, 'Evidência de entrega vinculada automaticamente.');
            } elseif (str_contains($title, 'comprovantes de despesas') && $financial['paid'] > 0) {
                $data = $this->resolvedRequirement($user, AccountabilityRequirement::STATUS_COMPLETED, $anyDocument?->id, 'Pagamentos registrados no TrilhaGov foram localizados para esta emenda.');
            } elseif (str_contains($title, 'extrato') && abs($financial['unreconciled']) <= 0.01 && $amendment->received_amount !== null) {
                $data = $this->resolvedRequirement($user, AccountabilityRequirement::STATUS_COMPLETED, $anyDocument?->id, 'Conciliação financeira fechada automaticamente: recebido, pago e devolvido conferidos.');
            } elseif (str_contains($title, 'devolucao de saldo') && abs($financial['unreconciled']) <= 0.01 && (float) $process->returned_amount <= 0) {
                $data = $this->resolvedRequirement($user, AccountabilityRequirement::STATUS_NOT_APPLICABLE, null, 'Sem saldo a devolver conforme conciliação financeira do sistema.');
            }

            if ($data === null) {
                continue;
            }

            $requirement->update($data);
            $updated++;
            $data['status'] === AccountabilityRequirement::STATUS_NOT_APPLICABLE ? $notApplicable++ : $completed++;
        }

        return compact('updated', 'completed', 'notApplicable');
    }

    /** @return array{received: float, paid: float, returned: float, unreconciled: float} */
    public function financialSummary(ParliamentaryAmendment $amendment, ?AccountabilityProcess $process = null): array
    {
        $commitments = $amendment->relationLoaded('financialCommitments')
            ? $amendment->financialCommitments
            : $amendment->financialCommitments()->with('payments')->get();
        $paid = (float) $commitments
            ->where('status', 'active')
            ->sum(fn ($commitment) => $commitment->payments->sum('amount'));
        $received = (float) ($amendment->received_amount ?? 0);
        $returned = (float) ($process?->returned_amount ?? 0);

        return [
            'received' => $received,
            'paid' => $paid,
            'returned' => $returned,
            'unreconciled' => $received - $paid - $returned,
        ];
    }

    /** @return array{score: int, ready: bool, blockers: Collection<int, string>, warnings: Collection<int, string>, required_total: int, required_resolved: int, checklist_percentage: int, financial: array{received: float, paid: float, returned: float, unreconciled: float}} */
    public function readiness(ParliamentaryAmendment $amendment, AccountabilityProcess $process): array
    {
        $requirements = $process->relationLoaded('requirements')
            ? $process->requirements
            : $process->requirements()->get();
        $diligences = $process->relationLoaded('diligences')
            ? $process->diligences
            : $process->diligences()->get();
        $documents = $amendment->relationLoaded('documents')
            ? $amendment->documents
            : $amendment->documents()->get();
        $stages = $amendment->relationLoaded('executionStages')
            ? $amendment->executionStages
            : $amendment->executionStages()->get();
        $required = $requirements->where('is_required', true);
        $resolved = $required->whereIn('status', [
            AccountabilityRequirement::STATUS_COMPLETED,
            AccountabilityRequirement::STATUS_NOT_APPLICABLE,
        ]);
        $financial = $this->financialSummary($amendment, $process);
        $physicalPercentage = $amendment->physicalExecutionPercentage();
        $physicalComplete = $stages->isNotEmpty()
            && $stages->every(fn ($stage) => $stage->status === 'completed' && $stage->progress_percentage === 100);
        $overdueDiligences = $diligences->filter->isOverdue();
        $blockers = collect();
        $warnings = collect();

        if ($required->count() !== $resolved->count()) {
            $blockers->push(($required->count() - $resolved->count()).' item(ns) obrigatório(s) do checklist ainda estão pendentes.');
        }

        if (! $physicalComplete) {
            $blockers->push("A execução física está em {$physicalPercentage}% e precisa ser concluída.");
        }

        if ($amendment->received_amount === null) {
            $blockers->push('O valor recebido ainda não foi informado na emenda.');
        } elseif (abs($financial['unreconciled']) > 0.01) {
            $blockers->push('Existe saldo financeiro sem conciliação de R$ '.number_format($financial['unreconciled'], 2, ',', '.').'.');
        }

        if ($overdueDiligences->isNotEmpty()) {
            $blockers->push($overdueDiligences->count().' diligência(s) aberta(s) estão com prazo vencido.');
        }

        if ($documents->whereNotNull('execution_stage_id')->isEmpty()) {
            $blockers->push('Nenhuma evidência de entrega foi vinculada às etapas de execução.');
        }

        $openDiligences = $diligences->where('status', 'open')->count();

        if ($openDiligences > $overdueDiligences->count()) {
            $warnings->push(($openDiligences - $overdueDiligences->count()).' diligência(s) ainda estão abertas.');
        }

        if ($process->due_at === null) {
            $warnings->push('O prazo da prestação de contas ainda não foi definido.');
        }

        $checks = [
            $required->count() === $resolved->count(),
            $physicalComplete,
            $amendment->received_amount !== null && abs($financial['unreconciled']) <= 0.01,
            $overdueDiligences->isEmpty(),
            $documents->whereNotNull('execution_stage_id')->isNotEmpty(),
        ];

        return [
            'score' => (int) round((collect($checks)->filter()->count() / count($checks)) * 100),
            'ready' => $blockers->isEmpty(),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'required_total' => $required->count(),
            'required_resolved' => $resolved->count(),
            'checklist_percentage' => $required->isEmpty()
                ? 100
                : (int) round(($resolved->count() / $required->count()) * 100),
            'financial' => $financial,
        ];
    }

    public function ensureReadyForSubmission(ParliamentaryAmendment $amendment, AccountabilityProcess $process): void
    {
        $readiness = $this->readiness($amendment, $process);

        if (! $readiness['ready']) {
            throw ValidationException::withMessages([
                'status' => 'A prestação ainda não pode ser enviada: '.$readiness['blockers']->first(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function resolvedRequirement(User $user, string $status, ?int $documentId, string $notes): array
    {
        return [
            'status' => $status,
            'amendment_document_id' => $documentId,
            'notes' => $notes,
            'completed_by' => $user->id,
            'completed_at' => now(),
        ];
    }
}
