<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var array<int, string|null> */
    private array $municipalityRoleCache = [];

    public const ROLE_MANAGER = 'manager';

    public const ROLE_EDITOR = 'editor';

    public const ROLE_VIEWER = 'viewer';

    public const ROLE_AUDITOR = 'auditor';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function municipalities(): BelongsToMany
    {
        return $this->belongsToMany(Municipality::class)
            ->withPivot([
                'role',
                'notify_in_app',
                'notify_email',
                'notify_deadlines',
                'notify_integrity',
            ])
            ->withTimestamps();
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    public function responsibleAmendments(): HasMany
    {
        return $this->hasMany(ParliamentaryAmendment::class, 'responsible_user_id');
    }

    public function responsibleAccountabilityProcesses(): HasMany
    {
        return $this->hasMany(AccountabilityProcess::class, 'responsible_user_id');
    }

    public function assignedAccountabilityDiligences(): HasMany
    {
        return $this->hasMany(AccountabilityDiligence::class, 'assigned_user_id');
    }

    public function assignedTechnicalImpediments(): HasMany
    {
        return $this->hasMany(TechnicalImpediment::class, 'assigned_user_id');
    }

    public function assignedTechnicalDiligences(): HasMany
    {
        return $this->hasMany(TechnicalDiligence::class, 'assigned_user_id');
    }

    /** @return array<string, string> */
    public static function municipalityRoles(): array
    {
        return [
            self::ROLE_MANAGER => 'Gestor',
            self::ROLE_EDITOR => 'Editor',
            self::ROLE_VIEWER => 'Consulta',
            self::ROLE_AUDITOR => 'Auditoria',
        ];
    }

    public function roleForMunicipality(int $municipalityId): ?string
    {
        if ($municipalityId <= 0) {
            return null;
        }

        if (array_key_exists($municipalityId, $this->municipalityRoleCache)) {
            return $this->municipalityRoleCache[$municipalityId];
        }

        return $this->municipalityRoleCache[$municipalityId] = $this->municipalities()
            ->whereKey($municipalityId)
            ->value('municipality_user.role');
    }

    public function canEditMunicipality(int $municipalityId): bool
    {
        return in_array(
            $this->roleForMunicipality($municipalityId),
            [self::ROLE_MANAGER, self::ROLE_EDITOR],
            true,
        );
    }
}
