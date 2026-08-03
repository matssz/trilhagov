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
    public function guide(ParliamentaryAmendment $amendment, ?AccountabilityProcess $process, ?array $readiness): array
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
        $closingLabel = $ready ? 'Pronta para protocolo' : ($hasProcess ? 'Fechamento em andamento' : 'Prestacao nao iniciada');
        $physicalComplete = $physicalPercentage >= 100;
        $hasEvidence = $evidenceCount > 0;

        $next = [
            'icon' => 'clipboard-list',
            'title' => 'Iniciar prestacao simplificada',
            'description' => 'Abra o processo para o sistema montar o checklist e ler execucao, pagamentos e evidencias ja registrados.',
            'href' => '#iniciar-prestacao',
            'label' => 'Iniciar processo',
        ];

        if ($hasProcess && ! $checklistReady) {
            $next = [
                'icon' => 'wand-sparkles',
                'title' => 'Pre-conferir checklist',
                'description' => 'Use os dados da execucao para resolver automaticamente itens que ja possuem lastro no sistema.',
                'href' => '#assistente-prestacao',
                'label' => 'Pre-conferir agora',
            ];
        } elseif ($hasProcess && ! $financialReady) {
            $next = [
                'icon' => 'scale',
                'title' => 'Conciliar saldo',
                'description' => 'Ajuste pagamentos ou devolucao para fechar recebido, pago e saldo devolvido.',
                'href' => '#reconciliation',
                'label' => 'Ver conciliacao',
            ];
        } elseif ($hasProcess && ! $ready) {
            $next = [
                'icon' => 'list-checks',
                'title' => 'Resolver pendencias finais',
                'description' => 'Finalize execucao fisica, evidencias ou diligencias antes do protocolo.',
                'href' => '#requirements',
                'label' => 'Ver pendencias',
            ];
        } elseif ($hasProcess) {
            $next = [
                'icon' => 'send',
                'title' => 'Enviar prestacao de contas',
                'description' => 'A base esta pronta para informar protocolo, data de envio e gerar o dossie.',
                'href' => '#process',
                'label' => 'Informar protocolo',
            ];
        }

        return [
            'next' => $next,
            'command' => [
                [
                    'icon' => 'wand-sparkles',
                    'label' => 'Preparar processo',
                    'metric' => $hasProcess ? 'Aberto' : 'Nao iniciado',
                    'description' => $hasProcess
                        ? 'Checklist e responsavel ja existem para esta emenda.'
                        : 'Cria a prestacao e preenche o que o sistema ja consegue validar.',
                    'href' => $hasProcess ? '#process' : '#iniciar-prestacao',
                    'cta' => $hasProcess ? 'Ver processo' : 'Preparar agora',
                    'tone' => $hasProcess ? 'success' : 'warning',
                ],
                [
                    'icon' => 'list-checks',
                    'label' => 'Conferir checklist',
                    'metric' => $process ? ($readiness['required_resolved'] ?? 0).'/'.($readiness['required_total'] ?? 0) : '0/0',
                    'description' => $checklistReady
                        ? 'Itens obrigatorios resolvidos ou marcados como nao aplicaveis.'
                        : 'Use a pre-conferencia para aproveitar dados de execucao e documentos.',
                    'href' => $process && ! $checklistReady ? '#assistente-prestacao' : '#requirements',
                    'cta' => $checklistReady ? 'Ver checklist' : 'Pre-conferir',
                    'tone' => $checklistReady ? 'success' : 'warning',
                ],
                [
                    'icon' => 'scale',
                    'label' => 'Conciliar saldo',
                    'metric' => 'R$ '.number_format($financial['unreconciled'], 2, ',', '.'),
                    'description' => $financialReady
                        ? 'Recebido, pago e devolvido estao fechados.'
                        : 'Ajuste pagamento ou devolucao antes de registrar envio.',
                    'href' => '#reconciliation',
                    'cta' => 'Abrir conciliacao',
                    'tone' => $financialReady ? 'success' : 'danger',
                ],
                [
                    'icon' => 'send',
                    'label' => 'Enviar prestacao',
                    'metric' => $ready ? 'Liberada' : $score.'%',
                    'description' => $ready
                        ? 'Informe protocolo e mantenha o dossie pronto para auditoria.'
                        : 'Finalize pendencias antes de protocolar.',
                    'href' => $ready ? '#envio-prestacao' : '#requirements',
                    'cta' => $ready ? 'Informar protocolo' : 'Ver pendencias',
                    'tone' => $ready ? 'success' : 'warning',
                ],
                [
                    'icon' => 'package-check',
                    'label' => 'Dossie',
                    'metric' => $hasProcess ? 'Disponivel' : 'Aguardando',
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
                'description' => 'Fechamento, checklist e dossie em uma trilha.',
                'facts' => [
                    ['label' => 'Checklist', 'value' => $process ? ($readiness['required_resolved'] ?? 0).'/'.($readiness['required_total'] ?? 0) : 'Nao iniciado'],
                    ['label' => 'Saldo', 'value' => 'R$ '.number_format($financial['unreconciled'], 2, ',', '.')],
                    ['label' => 'Diligencias', 'value' => $openDiligences.' aberta(s)'],
                ],
            ],
            'flow' => [
                ['label' => 'Abrir processo', 'description' => 'Cria checklist e responsavel.', 'done' => $hasProcess, 'href' => $hasProcess ? '#process' : '#iniciar-prestacao'],
                ['label' => 'Pre-conferir', 'description' => 'Resolve itens com dados ja registrados.', 'done' => $checklistReady, 'href' => '#assistente-prestacao'],
                ['label' => 'Conciliar saldo', 'description' => 'Fecha recebido, pago e devolvido.', 'done' => $financialReady, 'href' => '#reconciliation'],
                ['label' => 'Resolver pendencias', 'description' => 'Checklist, evidencias e diligencias.', 'done' => $ready, 'href' => '#requirements'],
                ['label' => 'Gerar dossie', 'description' => 'Baixa PDF e pacote de anexos.', 'done' => $ready, 'href' => '#dossie-prestacao'],
            ],
            'actions' => [
                [
                    'key' => 'quick-check',
                    'icon' => 'wand-sparkles',
                    'label' => 'Pre-conferir agora',
                    'description' => 'Marca automaticamente checklist, saldo e evidencias que ja possuem base no sistema.',
                    'href' => '#assistente-prestacao',
                    'enabled' => $hasProcess && ! $checklistReady,
                ],
                [
                    'key' => 'execution',
                    'icon' => 'route',
                    'label' => $physicalComplete && $hasEvidence ? 'Revisar execucao' : 'Completar execucao',
                    'description' => $physicalComplete && $hasEvidence
                        ? 'Confira etapas, pagamentos e documentos usados na prestacao.'
                        : 'Conclua etapas fisicas, pagamentos e evidencias antes de enviar.',
                    'href' => route('emendas.execution', $amendment),
                    'enabled' => $hasProcess && (! $physicalComplete || ! $hasEvidence),
                ],
                [
                    'key' => 'reconciliation',
                    'icon' => 'scale',
                    'label' => 'Conciliar saldo',
                    'description' => 'Ajuste valor devolvido, referencia e observacoes quando houver diferenca financeira.',
                    'href' => '#process',
                    'enabled' => $hasProcess && ! $financialReady,
                ],
                [
                    'key' => 'diligences',
                    'icon' => 'message-square-warning',
                    'label' => 'Resolver diligencias',
                    'description' => 'Responda pendencias abertas e registre protocolo de retorno quando existir.',
                    'href' => '#diligences',
                    'enabled' => $hasProcess && $openDiligences > 0,
                ],
                [
                    'key' => 'submit',
                    'icon' => 'send',
                    'label' => 'Registrar envio',
                    'description' => 'Informe data e protocolo quando a prestacao estiver pronta.',
                    'href' => '#envio-prestacao',
                    'enabled' => $ready,
                ],
                [
                    'key' => 'dossier',
                    'icon' => 'package-check',
                    'label' => 'Baixar dossie',
                    'description' => 'Gere PDF executivo e pacote com anexos para auditoria ou arquivo municipal.',
                    'href' => '#dossie-prestacao',
                    'enabled' => $hasProcess,
                ],
            ],
            'steps' => [
                ['label' => 'Processo aberto', 'description' => 'Checklist municipal criado.', 'done' => $hasProcess],
                ['label' => 'Execucao concluida', 'description' => 'Etapas fisicas em 100%.', 'done' => $physicalComplete],
                ['label' => 'Financeiro conciliado', 'description' => 'Recebido, pago e devolvido fecham.', 'done' => $financialReady],
                ['label' => 'Evidencias vinculadas', 'description' => 'Documentos ligados as entregas.', 'done' => $hasEvidence],
                ['label' => 'Pronta para envio', 'description' => 'Sem bloqueios para protocolo.', 'done' => $ready],
            ],
            'summary' => [
                ['label' => 'Execucao fisica', 'value' => $physicalPercentage.'%'],
                ['label' => 'Evidencias', 'value' => $evidenceCount.' documento(s)'],
                ['label' => 'Saldo sem conciliacao', 'value' => 'R$ '.number_format($financial['unreconciled'], 2, ',', '.')],
                ['label' => 'Checklist', 'value' => $process ? ($readiness['required_resolved'] ?? 0).'/'.($readiness['required_total'] ?? 0) : 'Nao iniciado'],
            ],
            'finalPackage' => [
                'ready' => $ready,
                'title' => $ready ? 'Prestacao final pronta' : 'Prestacao final em preparacao',
                'description' => $ready
                    ? 'O processo ja possui base para protocolo, PDF executivo e pacote de documentos.'
                    : 'O sistema mostra exatamente o que falta antes do envio final ao controle interno ou externo.',
                'checks' => [
                    ['label' => 'Processo aberto', 'done' => $hasProcess, 'detail' => $hasProcess ? 'Prestacao criada' : 'Inicie o processo'],
                    ['label' => 'Checklist resolvido', 'done' => $checklistReady, 'detail' => $process ? (($readiness['required_resolved'] ?? 0).'/'.($readiness['required_total'] ?? 0).' itens') : 'Nao iniciado'],
                    ['label' => 'Financeiro conciliado', 'done' => $financialReady, 'detail' => 'Diferenca R$ '.number_format($financial['unreconciled'], 2, ',', '.')],
                    ['label' => 'Evidencias anexadas', 'done' => $hasEvidence, 'detail' => $evidenceCount.' documento(s)'],
                    ['label' => 'Sem bloqueios', 'done' => $ready, 'detail' => $ready ? 'Pronto para protocolo' : (($readiness['blockers'] ?? collect())->first() ?? 'Aguardando preparo')],
                ],
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
                $data = $this->resolvedRequirement($user, AccountabilityRequirement::STATUS_COMPLETED, $evidence->id, 'Execucao fisica concluida e evidencia de entrega localizada automaticamente.');
            } elseif (str_contains($title, 'comprovacao') && $evidence) {
                $data = $this->resolvedRequirement($user, AccountabilityRequirement::STATUS_COMPLETED, $evidence->id, 'Evidencia de entrega vinculada automaticamente.');
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
