<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LegislativeProposal extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SENT = 'sent';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_RESERVED = 'reserved';

    protected $fillable = [
        'municipality_id', 'municipal_regulatory_profile_id', 'submitted_by', 'reviewed_by',
        'received_by', 'parliamentary_amendment_id', 'reference', 'fiscal_year', 'author_name',
        'author_party', 'object', 'justification', 'priority', 'beneficiary_type',
        'beneficiary_name', 'beneficiary_cnpj', 'beneficiary_location', 'expense_destination',
        'transfer_type', 'health_related', 'responsible_department', 'program_reference',
        'action_reference', 'public_need', 'target_population', 'estimated_quantity',
        'estimated_amount', 'estimate_source', 'desired_contract_at',
        'third_sector_conflict_declaration', 'status', 'review_ppa', 'review_ldo', 'review_loa',
        'review_sector_plan', 'review_budget_limit', 'review_health_reserve', 'review_object',
        'review_beneficiary', 'review_viability', 'review_notes', 'protocol_number',
        'executive_process_number', 'budget_reservation_number', 'budget_reserved_amount',
        'budget_reserved_at', 'executive_notes', 'submitted_at', 'reviewed_at', 'sent_at',
        'received_at', 'protocol_snapshot', 'protocol_sha256',
    ];

    protected function casts(): array
    {
        return [
            'health_related' => 'boolean',
            'third_sector_conflict_declaration' => 'boolean',
            'review_ppa' => 'boolean',
            'review_ldo' => 'boolean',
            'review_loa' => 'boolean',
            'review_sector_plan' => 'boolean',
            'review_budget_limit' => 'boolean',
            'review_health_reserve' => 'boolean',
            'review_object' => 'boolean',
            'review_beneficiary' => 'boolean',
            'review_viability' => 'boolean',
            'estimated_amount' => 'decimal:2',
            'budget_reserved_amount' => 'decimal:2',
            'desired_contract_at' => 'date',
            'budget_reserved_at' => 'date',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'protocol_snapshot' => 'array',
        ];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT => 'Em elaboração',
            self::STATUS_SUBMITTED => 'Em análise legislativa',
            self::STATUS_RETURNED => 'Devolvida para ajuste',
            self::STATUS_APPROVED => 'Aprovada pela análise prévia',
            self::STATUS_REJECTED => 'Rejeitada pela análise prévia',
            self::STATUS_SENT => 'Protocolada no Executivo',
            self::STATUS_RECEIVED => 'Recebida pelo Executivo',
            self::STATUS_RESERVED => 'Reserva orçamentária registrada',
        ];
    }

    /** @return array<string, string> */
    public static function priorities(): array
    {
        return ['normal' => 'Normal', 'high' => 'Alta', 'urgent' => 'Urgente'];
    }

    /** @return array<string, string> */
    public static function beneficiaryTypes(): array
    {
        return [
            'municipal_body' => 'Órgão ou fundo municipal',
            'public_entity' => 'Entidade pública',
            'third_sector' => 'Organização da sociedade civil',
        ];
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function priorityLabel(): string
    {
        return self::priorities()[$this->priority] ?? $this->priority;
    }

    public function beneficiaryTypeLabel(): string
    {
        return self::beneficiaryTypes()[$this->beneficiary_type] ?? $this->beneficiary_type;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_RETURNED], true);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function regulatoryProfile(): BelongsTo
    {
        return $this->belongsTo(MunicipalRegulatoryProfile::class, 'municipal_regulatory_profile_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(LegislativeProposalEvent::class);
    }
}
