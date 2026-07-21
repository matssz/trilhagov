<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MunicipalWorkItem extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const PRIORITY_CRITICAL = 'critical';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_NORMAL = 'normal';

    protected $fillable = [
        'municipality_id',
        'parliamentary_amendment_id',
        'responsible_user_id',
        'source_key',
        'category',
        'title',
        'guidance',
        'action_url',
        'priority',
        'status',
        'due_at',
        'notes',
        'first_detected_at',
        'last_evaluated_at',
        'completed_at',
        'completion_reason',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'date',
            'first_detected_at' => 'datetime',
            'last_evaluated_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING => 'A fazer',
            self::STATUS_IN_PROGRESS => 'Em andamento',
            self::STATUS_COMPLETED => 'Resolvida',
        ];
    }

    /** @return array<string, string> */
    public static function priorities(): array
    {
        return [
            self::PRIORITY_CRITICAL => 'Crítica',
            self::PRIORITY_HIGH => 'Alta',
            self::PRIORITY_NORMAL => 'Normal',
        ];
    }

    /** @return array<string, string> */
    public static function categories(): array
    {
        return [
            'responsibility' => 'Responsabilidade',
            'communication' => 'Comunicação',
            'document' => 'Documentação',
            'planning' => 'Planejamento',
            'normative' => 'Normas municipais',
            'impediment' => 'Impedimentos',
            'execution' => 'Execução',
            'financial' => 'Financeiro',
            'accountability' => 'Prestação de contas',
            'control' => 'Controle Interno',
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

    public function categoryLabel(): string
    {
        return self::categories()[$this->category] ?? $this->category;
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MunicipalWorkItemEvent::class)
            ->latest('created_at')
            ->latest('id');
    }
}
