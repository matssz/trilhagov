<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class DocumentType extends Model
{
    protected $fillable = [
        'created_by',
        'name',
        'description',
        'is_required',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AmendmentDocument::class);
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return array<int, array{name: string, description: string}> */
    public static function suggestedDefaults(): array
    {
        return [
            ['name' => 'Plano de trabalho', 'description' => 'Planejamento, metas e aplicação prevista para o recurso.'],
            ['name' => 'Comprovante de recebimento', 'description' => 'Extrato ou documento que evidencia o ingresso do recurso.'],
            ['name' => 'Documento de contratação', 'description' => 'Processo, contrato ou instrumento relacionado à contratação.'],
            ['name' => 'Evidência de execução', 'description' => 'Comprovação da entrega física ou da execução do objeto.'],
            ['name' => 'Relatório de prestação de contas', 'description' => 'Relatório ou documento consolidado da prestação de contas.'],
        ];
    }

    public static function createDefaultsFor(Municipality $municipality): void
    {
        foreach (self::suggestedDefaults() as $position => $default) {
            $municipality->documentTypes()->firstOrCreate(
                ['name' => $default['name']],
                [
                    'description' => $default['description'],
                    'is_required' => false,
                    'is_active' => true,
                    'sort_order' => ($position + 1) * 10,
                ],
            );
        }
    }
}
