<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'municipality_id',
        'user_id',
        'actor_name',
        'action',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Registros de auditoria não podem ser alterados.'));
        static::deleting(fn () => throw new LogicException('Registros de auditoria não podem ser excluídos.'));
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            'created' => 'Emenda cadastrada',
            'updated' => 'Emenda atualizada',
            'role_updated' => 'Perfil de acesso atualizado',
            'document_uploaded' => 'Documento anexado',
            'document_type_created' => 'Tipo de documento criado',
            'document_type_updated' => 'Tipo de documento atualizado',
            default => 'Alteração registrada',
        };
    }

    /** @return array<int, array{label: string, old: string, new: string}> */
    public function changesForDisplay(): array
    {
        if ($this->action === 'created') {
            return [];
        }

        return collect($this->new_values ?? [])
            ->map(fn (mixed $value, string $field) => [
                'label' => self::fieldLabels()[$field] ?? Str::headline($field),
                'old' => $this->formatValue($field, ($this->old_values ?? [])[$field] ?? null),
                'new' => $this->formatValue($field, $value),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    private static function fieldLabels(): array
    {
        return [
            'role' => 'Perfil de acesso',
            'document_type' => 'Tipo de documento',
            'document_name' => 'Arquivo',
            'document_version' => 'Versão',
            'document_size' => 'Tamanho',
            'name' => 'Nome',
            'description' => 'Descrição',
            'is_required' => 'Obrigatório',
            'is_active' => 'Ativo',
            'sort_order' => 'Ordem',
            'reference' => 'Identificação',
            'fiscal_year' => 'Exercício',
            'government_sphere' => 'Esfera',
            'authorship_type' => 'Tipo de autoria',
            'transfer_type' => 'Modalidade',
            'author_name' => 'Autor',
            'author_party' => 'Partido',
            'object' => 'Objeto',
            'responsible_department' => 'Órgão responsável',
            'transferegov_code' => 'Código Transferegov',
            'expected_amount' => 'Valor previsto',
            'received_amount' => 'Valor recebido',
            'status' => 'Situação',
            'indicated_at' => 'Data da indicação',
            'received_at' => 'Data do recebimento',
            'communication_deadline' => 'Prazo de comunicação',
            'communication_completed_at' => 'Comunicação concluída em',
            'execution_deadline' => 'Prazo de execução',
            'execution_completed_at' => 'Execução concluída em',
            'accountability_deadline' => 'Prazo de prestação de contas',
            'accountability_completed_at' => 'Prestação de contas concluída em',
            'notes' => 'Observações internas',
        ];
    }

    private function formatValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'Não informado';
        }

        if ($field === 'role') {
            return User::municipalityRoles()[$value] ?? (string) $value;
        }

        if (in_array($field, ['is_required', 'is_active'], true)) {
            return $value ? 'Sim' : 'Não';
        }

        if (in_array($field, ['expected_amount', 'received_amount'], true)) {
            return 'R$ '.number_format((float) $value, 2, ',', '.');
        }

        if ($field === 'status') {
            return ParliamentaryAmendment::statuses()[$value] ?? (string) $value;
        }

        if ($field === 'government_sphere') {
            return ParliamentaryAmendment::governmentSpheres()[$value] ?? (string) $value;
        }

        if ($field === 'authorship_type') {
            return ParliamentaryAmendment::authorshipTypes()[$value] ?? (string) $value;
        }

        if ($field === 'transfer_type') {
            return ParliamentaryAmendment::transferTypes()[$value] ?? (string) $value;
        }

        if (str_ends_with($field, '_at') || str_ends_with($field, '_deadline')) {
            return Carbon::parse($value)->format('d/m/Y');
        }

        return Str::limit((string) $value, 180);
    }
}
