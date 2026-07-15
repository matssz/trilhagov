<?php

namespace App\Models;

use Database\Factories\ParliamentaryAmendmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

class ParliamentaryAmendment extends Model
{
    /** @use HasFactory<ParliamentaryAmendmentFactory> */
    use HasFactory;

    public const STATUS_IDENTIFIED = 'identified';

    public const STATUS_PLAN_PENDING = 'plan_pending';

    public const STATUS_UNDER_ANALYSIS = 'under_analysis';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_RESOURCE_RECEIVED = 'resource_received';

    public const STATUS_EXECUTING = 'executing';

    public const STATUS_ACCOUNTABILITY_PENDING = 'accountability_pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [
        'created_by',
        'reference',
        'fiscal_year',
        'government_sphere',
        'authorship_type',
        'transfer_type',
        'author_name',
        'author_party',
        'object',
        'responsible_department',
        'transferegov_code',
        'expected_amount',
        'received_amount',
        'status',
        'indicated_at',
        'received_at',
        'communication_deadline',
        'communication_completed_at',
        'execution_deadline',
        'execution_completed_at',
        'accountability_deadline',
        'accountability_completed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'expected_amount' => 'decimal:2',
            'received_amount' => 'decimal:2',
            'indicated_at' => 'date',
            'received_at' => 'date',
            'communication_deadline' => 'date',
            'communication_completed_at' => 'date',
            'execution_deadline' => 'date',
            'execution_completed_at' => 'date',
            'accountability_deadline' => 'date',
            'accountability_completed_at' => 'date',
        ];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_IDENTIFIED => 'Identificada',
            self::STATUS_PLAN_PENDING => 'Plano de trabalho pendente',
            self::STATUS_UNDER_ANALYSIS => 'Em análise',
            self::STATUS_APPROVED => 'Aprovada',
            self::STATUS_RESOURCE_RECEIVED => 'Recurso recebido',
            self::STATUS_EXECUTING => 'Em execução',
            self::STATUS_ACCOUNTABILITY_PENDING => 'Prestação de contas pendente',
            self::STATUS_COMPLETED => 'Concluída',
            self::STATUS_BLOCKED => 'Com impedimento',
        ];
    }

    /** @return array<string, string> */
    public static function governmentSpheres(): array
    {
        return ['federal' => 'Federal', 'state' => 'Estadual'];
    }

    /** @return array<string, string> */
    public static function authorshipTypes(): array
    {
        return [
            'individual' => 'Individual',
            'caucus' => 'Bancada',
            'commission' => 'Comissão',
            'other' => 'Outra',
        ];
    }

    /** @return array<string, string> */
    public static function transferTypes(): array
    {
        return [
            'special' => 'Transferência especial',
            'defined_purpose' => 'Finalidade definida',
            'agreement' => 'Convênio ou contrato de repasse',
            'fund_to_fund' => 'Fundo a fundo',
            'other' => 'Outra modalidade',
        ];
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function governmentSphereLabel(): string
    {
        return self::governmentSpheres()[$this->government_sphere] ?? $this->government_sphere;
    }

    public function authorshipTypeLabel(): string
    {
        return self::authorshipTypes()[$this->authorship_type] ?? $this->authorship_type;
    }

    public function transferTypeLabel(): string
    {
        return self::transferTypes()[$this->transfer_type] ?? $this->transfer_type;
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable')
            ->latest('created_at')
            ->latest('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AmendmentDocument::class)
            ->latest('created_at')
            ->latest('id');
    }

    public function integrityAlerts(): HasMany
    {
        return $this->hasMany(IntegrityAlert::class);
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->whereHas(
            'municipality.users',
            fn (Builder $query) => $query->where('users.id', $user->id),
        );
    }

    /** @return array{label: string, date: Carbon}|null */
    public function nextDeadline(): ?array
    {
        if ($this->status === self::STATUS_COMPLETED) {
            return null;
        }

        return collect([
            ['label' => 'Comunicação e publicidade', 'date' => $this->communication_deadline, 'completed_at' => $this->communication_completed_at],
            ['label' => 'Execução', 'date' => $this->execution_deadline, 'completed_at' => $this->execution_completed_at],
            ['label' => 'Prestação de contas', 'date' => $this->accountability_deadline, 'completed_at' => $this->accountability_completed_at],
        ])->filter(fn (array $deadline) => $deadline['date'] !== null && $deadline['completed_at'] === null)
            ->sortBy('date')
            ->first();
    }

    public function hasOverdueDeadline(): bool
    {
        $deadline = $this->nextDeadline();

        return $deadline !== null && $deadline['date']->isBefore(today());
    }

    public function hasUpcomingDeadline(): bool
    {
        $deadline = $this->nextDeadline();

        return $deadline !== null
            && $deadline['date']->betweenIncluded(today(), today()->addDays(30));
    }
}
