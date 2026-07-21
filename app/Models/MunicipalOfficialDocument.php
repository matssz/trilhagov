<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class MunicipalOfficialDocument extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'municipality_id', 'municipal_document_template_id', 'parliamentary_amendment_id',
        'technical_impediment_id', 'technical_diligence_id', 'municipal_internal_control_review_id',
        'created_by', 'issued_by', 'supersedes_id', 'reference', 'fiscal_year', 'sequence',
        'version', 'official_number', 'document_type', 'status', 'recipient_name',
        'recipient_role', 'recipient_entity', 'recipient_email', 'delivery_method',
        'protocol_number', 'subject', 'body', 'response_due_at', 'snapshot',
        'snapshot_sha256', 'issued_at', 'sent_at', 'acknowledged_at', 'rejected_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer', 'sequence' => 'integer', 'version' => 'integer',
            'response_due_at' => 'date', 'snapshot' => 'array', 'issued_at' => 'datetime',
            'sent_at' => 'datetime', 'acknowledged_at' => 'datetime',
            'rejected_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $document): void {
            $lifecycleFields = [
                'status', 'delivery_method', 'protocol_number', 'sent_at',
                'acknowledged_at', 'rejected_at', 'cancelled_at', 'updated_at',
            ];
            $contentChanged = array_diff(array_keys($document->getDirty()), $lifecycleFields) !== [];

            if ($document->getOriginal('status') !== self::STATUS_DRAFT && $contentChanged) {
                throw new LogicException('Documentos oficiais emitidos não podem ser alterados. Gere uma nova versão.');
            }
        });
        static::deleting(fn () => throw new LogicException('Documentos oficiais não podem ser excluídos.'));
    }

    public static function types(): array
    {
        return [
            'impediment_letter' => 'Ofício de impedimento',
            'notification' => 'Notificação',
            'diligence' => 'Diligência',
            'dispatch' => 'Despacho',
            'opinion' => 'Parecer',
            'forwarding_term' => 'Termo de encaminhamento',
        ];
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT => 'Minuta', self::STATUS_ISSUED => 'Emitido',
            self::STATUS_SENT => 'Protocolado', self::STATUS_ACKNOWLEDGED => 'Recebido',
            self::STATUS_REJECTED => 'Devolvido', self::STATUS_CANCELLED => 'Cancelado',
        ];
    }

    public static function deliveryMethods(): array
    {
        return MunicipalReportDispatch::deliveryMethods();
    }

    public function typeLabel(): string
    {
        return self::types()[$this->document_type] ?? $this->document_type;
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function deliveryMethodLabel(): string
    {
        return self::deliveryMethods()[$this->delivery_method] ?? $this->delivery_method;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MunicipalDocumentTemplate::class, 'municipal_document_template_id');
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }

    public function impediment(): BelongsTo
    {
        return $this->belongsTo(TechnicalImpediment::class, 'technical_impediment_id');
    }

    public function diligence(): BelongsTo
    {
        return $this->belongsTo(TechnicalDiligence::class, 'technical_diligence_id');
    }

    public function internalControlReview(): BelongsTo
    {
        return $this->belongsTo(MunicipalInternalControlReview::class, 'municipal_internal_control_review_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MunicipalOfficialDocumentEvent::class)->latest('occurred_at')->latest('id');
    }
}
