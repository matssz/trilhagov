<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmendmentComplianceReview extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLIANT = 'compliant';

    public const STATUS_NON_COMPLIANT = 'non_compliant';

    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    protected $fillable = [
        'municipality_id',
        'framework_version',
        'rule_code',
        'status',
        'evidence_notes',
        'amendment_document_id',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING => 'Pendente',
            self::STATUS_COMPLIANT => 'Atendido',
            self::STATUS_NON_COMPLIANT => 'Não atendido',
            self::STATUS_NOT_APPLICABLE => 'Não se aplica',
        ];
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(AmendmentDocument::class, 'amendment_document_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
