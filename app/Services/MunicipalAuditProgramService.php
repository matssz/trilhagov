<?php

namespace App\Services;

use App\Models\MunicipalAuditProcedure;
use App\Models\MunicipalAuditProgram;

class MunicipalAuditProgramService
{
    /** @return list<string> */
    public function readiness(MunicipalAuditProgram $program): array
    {
        $program->loadMissing(['procedures.evidences', 'procedures.findings', 'findings']);
        $blockers = [];

        if ($program->lead_auditor_id === $program->supervisor_id) {
            $blockers[] = 'Auditor líder e supervisor devem ser pessoas diferentes.';
        }
        if ($program->population_size === null || $program->population_size < 1) {
            $blockers[] = 'Informe o tamanho da população examinada.';
        }
        if ($program->sample_size === null || $program->sample_size < 1) {
            $blockers[] = 'Informe o tamanho da amostra selecionada.';
        } elseif ($program->population_size !== null && $program->sample_size > $program->population_size) {
            $blockers[] = 'A amostra não pode ser maior que a população.';
        }
        if ($program->sampling_method === 'census'
            && $program->population_size !== null
            && $program->sample_size !== $program->population_size) {
            $blockers[] = 'No exame integral, amostra e população devem possuir o mesmo tamanho.';
        }
        if ($program->procedures->isEmpty()) {
            $blockers[] = 'Inclua ao menos um procedimento de auditoria.';
        }

        foreach ($program->procedures as $procedure) {
            $code = 'P'.str_pad((string) $procedure->sequence, 2, '0', STR_PAD_LEFT);
            if ($procedure->status === MunicipalAuditProcedure::STATUS_PLANNED || blank($procedure->result)) {
                $blockers[] = "{$code}: registre o teste executado e seu resultado.";
            }
            if ($procedure->status !== MunicipalAuditProcedure::STATUS_NOT_APPLICABLE && $procedure->evidences->isEmpty()) {
                $blockers[] = "{$code}: anexe ao menos uma evidência ao papel de trabalho.";
            }
            if ($procedure->status === MunicipalAuditProcedure::STATUS_EXCEPTION && $procedure->findings->isEmpty()) {
                $blockers[] = "{$code}: transforme a exceção identificada em achado estruturado.";
            }
        }

        return $blockers;
    }

    /** @return array<string, mixed> */
    public function snapshot(MunicipalAuditProgram $program): array
    {
        $program->loadMissing([
            'municipality:id,name,state,cnpj,ibge_code',
            'planItem.plan', 'planItem.amendment:id,reference,object,administrative_process',
            'leadAuditor:id,name,email', 'supervisor:id,name,email', 'reviewer:id,name',
            'procedures.executor:id,name', 'procedures.evidences', 'findings.procedure',
        ]);

        return [
            'reference' => $program->reference(),
            'generated_at' => now()->toIso8601String(),
            'municipality' => $program->municipality->only(['id', 'name', 'state', 'cnpj', 'ibge_code']),
            'annual_plan' => [
                'reference' => $program->planItem->plan->reference(),
                'item_reference' => $program->planItem->formalReference(),
                'phase' => $program->planItem->phase,
                'priority' => $program->planItem->priority,
            ],
            'amendment' => $program->planItem->amendment->only(['id', 'reference', 'object', 'administrative_process']),
            'program' => [
                'title' => $program->title,
                'objective' => $program->objective,
                'scope' => $program->scope,
                'sampling_method' => $program->sampling_method,
                'population_description' => $program->population_description,
                'population_size' => $program->population_size,
                'sample_size' => $program->sample_size,
                'materiality_criteria' => $program->materiality_criteria,
                'start_at' => $program->start_at->format('Y-m-d'),
                'due_at' => $program->due_at->format('Y-m-d'),
                'lead_auditor' => $program->leadAuditor->only(['id', 'name', 'email']),
                'supervisor' => $program->supervisor->only(['id', 'name', 'email']),
                'reviewer' => $program->reviewer?->only(['id', 'name']),
                'supervisor_notes' => $program->supervisor_notes,
                'conclusion' => $program->conclusion,
            ],
            'procedures' => $program->procedures->map(fn ($procedure) => [
                'sequence' => $procedure->sequence,
                'title' => $procedure->title,
                'objective' => $procedure->objective,
                'test_method' => $procedure->test_method,
                'sample_description' => $procedure->sample_description,
                'expected_evidence' => $procedure->expected_evidence,
                'status' => $procedure->status,
                'result' => $procedure->result,
                'executed_by' => $procedure->executor?->name,
                'executed_at' => $procedure->executed_at?->toIso8601String(),
                'evidences' => $procedure->evidences->map(fn ($evidence) => [
                    'description' => $evidence->description,
                    'original_name' => $evidence->original_name,
                    'mime_type' => $evidence->mime_type,
                    'size_bytes' => $evidence->size_bytes,
                    'sha256' => $evidence->sha256,
                    'uploader_name' => $evidence->uploader_name,
                    'created_at' => $evidence->created_at->toIso8601String(),
                ])->all(),
            ])->all(),
            'findings' => $program->findings->map(fn ($finding) => [
                'procedure_sequence' => $finding->procedure?->sequence,
                'severity' => $finding->severity,
                'title' => $finding->title,
                'criteria' => $finding->criteria,
                'condition' => $finding->condition,
                'cause' => $finding->cause,
                'effect' => $finding->effect,
                'recommendation' => $finding->recommendation,
                'recommended_due_at' => $finding->recommended_due_at?->format('Y-m-d'),
            ])->all(),
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
