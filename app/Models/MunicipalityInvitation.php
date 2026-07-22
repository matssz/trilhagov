<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MunicipalityInvitation extends Model
{
    protected $fillable = [
        'municipality_id',
        'invited_by',
        'email',
        'role',
        'token_hash',
        'expires_at',
        'accepted_at',
        'revoked_at',
        'legislative_name',
        'legislative_party',
        'legislative_term_start',
        'legislative_term_end',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'legislative_term_start' => 'date',
            'legislative_term_end' => 'date',
        ];
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    public static function findAvailableByToken(string $token): self
    {
        return self::query()
            ->available()
            ->where('token_hash', hash('sha256', $token))
            ->firstOrFail();
    }

    public function roleLabel(): string
    {
        return User::municipalityRoles()[$this->role] ?? $this->role;
    }
}
