<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class MunicipalReportDispatch extends Model
{
    public const STATUS_PREPARED = 'prepared';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'municipality_id', 'municipal_governance_report_id', 'created_by',
        'responsible_user_id', 'retry_of_id', 'reference', 'recipient_type',
        'recipient_name', 'recipient_unit', 'recipient_email', 'delivery_method',
        'legal_basis', 'due_at', 'status', 'official_document_number',
        'protocol_number', 'sent_at', 'acknowledged_at', 'rejected_at',
        'cancelled_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'date',
            'sent_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Remessas institucionais não podem ser excluídas.'));
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_PREPARED => 'Preparada',
            self::STATUS_SENT => 'Enviada',
            self::STATUS_ACKNOWLEDGED => 'Recebimento confirmado',
            self::STATUS_REJECTED => 'Devolvida',
            self::STATUS_CANCELLED => 'Cancelada',
        ];
    }

    public static function recipientTypes(): array
    {
        return [
            'chamber' => 'Câmara Municipal',
            'internal_control' => 'Controle Interno',
            'accounting' => 'Contabilidade Municipal',
            'mayor_office' => 'Gabinete do Prefeito',
            'other' => 'Outro órgão municipal',
        ];
    }

    public static function deliveryMethods(): array
    {
        return [
            'electronic_protocol' => 'Protocolo eletrônico',
            'official_email' => 'E-mail institucional',
            'in_person' => 'Protocolo presencial',
            'official_letter' => 'Ofício físico',
            'other' => 'Outro meio formal',
        ];
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function recipientTypeLabel(): string
    {
        return self::recipientTypes()[$this->recipient_type] ?? $this->recipient_type;
    }

    public function deliveryMethodLabel(): string
    {
        return self::deliveryMethods()[$this->delivery_method] ?? $this->delivery_method;
    }

    public function code(): string
    {
        return 'REM-'.$this->report->code().'-'.str_pad((string) $this->id, 3, '0', STR_PAD_LEFT);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PREPARED, self::STATUS_SENT], true);
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_PREPARED && $this->due_at->isBefore(today());
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(MunicipalGovernanceReport::class, 'municipal_governance_report_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_id');
    }

    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'retry_of_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MunicipalReportDispatchEvent::class)
            ->latest('occurred_at')
            ->latest('id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(MunicipalReportDispatchDelivery::class);
    }
}
