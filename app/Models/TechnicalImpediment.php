<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TechnicalImpediment extends Model
{
    public const STATUS_IDENTIFIED = 'identified';

    public const STATUS_UNDER_DILIGENCE = 'under_diligence';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REMAPPED = 'remapped';

    public const NATURE_UNDER_ANALYSIS = 'under_analysis';

    public const NATURE_TEMPORARY = 'temporary';

    public const NATURE_INSURMOUNTABLE = 'insurmountable';

    protected $fillable = [
        'municipality_id',
        'parliamentary_amendment_id',
        'created_by',
        'assigned_user_id',
        'evidence_document_id',
        'category',
        'nature',
        'status',
        'title',
        'description',
        'impact',
        'identified_at',
        'resolution_due_at',
        'resolution_notes',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'identified_at' => 'date',
            'resolution_due_at' => 'date',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return array<string, string> */
    public static function categories(): array
    {
        return [
            'documentary' => 'Documental',
            'technical' => 'Técnico',
            'engineering' => 'Engenharia ou projeto',
            'environmental' => 'Licenciamento ambiental',
            'legal' => 'Jurídico ou normativo',
            'budgetary' => 'Orçamentário',
            'beneficiary' => 'Beneficiário',
            'procurement' => 'Contratação',
            'other' => 'Outro',
        ];
    }

    /** @return array<string, string> */
    public static function natures(): array
    {
        return [
            self::NATURE_UNDER_ANALYSIS => 'Em análise',
            self::NATURE_TEMPORARY => 'Temporário',
            self::NATURE_INSURMOUNTABLE => 'Insuperável',
        ];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_IDENTIFIED => 'Identificado',
            self::STATUS_UNDER_DILIGENCE => 'Em diligência',
            self::STATUS_RESOLVED => 'Resolvido',
            self::STATUS_CONFIRMED => 'Impedimento confirmado',
            self::STATUS_REMAPPED => 'Remanejado',
        ];
    }

    public function categoryLabel(): string
    {
        return self::categories()[$this->category] ?? $this->category;
    }

    public function natureLabel(): string
    {
        return self::natures()[$this->nature] ?? $this->nature;
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_IDENTIFIED, self::STATUS_UNDER_DILIGENCE], true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->resolution_due_at?->isBefore(today()) === true;
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function evidenceDocument(): BelongsTo
    {
        return $this->belongsTo(AmendmentDocument::class, 'evidence_document_id');
    }

    public function diligences(): HasMany
    {
        return $this->hasMany(TechnicalDiligence::class)->latest('requested_at')->latest('id');
    }

    public function remappings(): HasMany
    {
        return $this->hasMany(AmendmentRemapping::class)->latest('created_at');
    }

    public function currentRemapping(): HasOne
    {
        return $this->hasOne(AmendmentRemapping::class)->latestOfMany();
    }
}
