<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class HealthAspsAssessment extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_ISSUED = 'issued';

    public const CONCLUSION_ELIGIBLE = 'eligible';

    public const CONCLUSION_INELIGIBLE = 'ineligible';

    protected $fillable = [
        'municipality_id', 'parliamentary_amendment_id', 'created_by', 'updated_by',
        'submitted_by', 'reviewed_by', 'evidence_document_id', 'supersedes_id',
        'reference', 'fiscal_year', 'version', 'status', 'conclusion', 'asps_category',
        'criteria', 'exclusion_reasons', 'budget_function', 'budget_subfunction',
        'funding_source_code', 'application_code', 'health_fund_reference',
        'health_plan_reference', 'technical_justification', 'reviewer_notes',
        'snapshot', 'snapshot_sha256', 'submitted_at', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'version' => 'integer',
            'criteria' => 'array',
            'exclusion_reasons' => 'array',
            'snapshot' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $assessment): void {
            if ($assessment->getOriginal('status') === self::STATUS_ISSUED) {
                throw new LogicException('Um parecer ASPS emitido não pode ser alterado. Abra uma nova versão.');
            }
        });

        static::deleting(function (self $assessment): void {
            if ($assessment->status === self::STATUS_ISSUED) {
                throw new LogicException('Um parecer ASPS emitido não pode ser excluído.');
            }
        });
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT => 'Em elaboração',
            self::STATUS_UNDER_REVIEW => 'Em revisão',
            self::STATUS_RETURNED => 'Ajustes solicitados',
            self::STATUS_ISSUED => 'Parecer emitido',
        ];
    }

    /** @return array<string, string> */
    public static function conclusions(): array
    {
        return [
            self::CONCLUSION_ELIGIBLE => 'Computável como ASPS',
            self::CONCLUSION_INELIGIBLE => 'Não computável como ASPS',
        ];
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function conclusionLabel(): string
    {
        return self::conclusions()[$this->conclusion] ?? 'Sem conclusão';
    }

    public function code(): string
    {
        return sprintf('ASPS-%d-%05d-V%d', $this->fiscal_year, $this->parliamentary_amendment_id, $this->version);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_RETURNED], true);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function evidenceDocument(): BelongsTo
    {
        return $this->belongsTo(AmendmentDocument::class, 'evidence_document_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }
}
