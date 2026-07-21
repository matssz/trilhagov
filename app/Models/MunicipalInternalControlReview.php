<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class MunicipalInternalControlReview extends Model
{
    public const UPDATED_AT = null;

    public const PHASE_PRIOR = 'prior';

    public const PHASE_CONCOMITANT = 'concomitant';

    public const PHASE_FINAL = 'final';

    public const CONCLUSION_REGULAR = 'regular';

    public const CONCLUSION_RECOMMENDATIONS = 'regular_with_recommendations';

    public const CONCLUSION_DILIGENCE = 'diligence';

    public const CONCLUSION_IRREGULAR = 'irregular';

    protected $fillable = [
        'municipality_id', 'parliamentary_amendment_id', 'municipal_governance_report_id',
        'reviewed_by', 'sequence', 'reference', 'phase', 'conclusion', 'criteria',
        'summary', 'findings', 'recommendations', 'annual_audit_plan_reference',
        'legal_basis', 'snapshot', 'snapshot_sha256', 'evidence_path',
        'evidence_original_name', 'evidence_mime', 'evidence_size', 'evidence_sha256',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'criteria' => 'array',
            'snapshot' => 'array',
            'evidence_size' => 'integer',
            'issued_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Pareceres do Controle Interno não podem ser alterados.'));
        static::deleting(fn () => throw new LogicException('Pareceres do Controle Interno não podem ser excluídos.'));
    }

    /** @return array<string, string> */
    public static function phases(): array
    {
        return [
            self::PHASE_PRIOR => 'Prévia',
            self::PHASE_CONCOMITANT => 'Concomitante',
            self::PHASE_FINAL => 'Final',
        ];
    }

    /** @return array<string, string> */
    public static function conclusions(): array
    {
        return [
            self::CONCLUSION_REGULAR => 'Regular',
            self::CONCLUSION_RECOMMENDATIONS => 'Regular com recomendações',
            self::CONCLUSION_DILIGENCE => 'Diligência necessária',
            self::CONCLUSION_IRREGULAR => 'Irregular',
        ];
    }

    public function phaseLabel(): string
    {
        return self::phases()[$this->phase] ?? $this->phase;
    }

    public function conclusionLabel(): string
    {
        return self::conclusions()[$this->conclusion] ?? $this->conclusion;
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }

    public function governanceReport(): BelongsTo
    {
        return $this->belongsTo(MunicipalGovernanceReport::class, 'municipal_governance_report_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(MunicipalInternalControlAction::class);
    }
}
