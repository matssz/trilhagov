<?php

namespace App\Services;

use App\Models\AccountabilityProcess;
use App\Models\AccountabilityRequirement;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use Illuminate\Support\Collection;
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
}
