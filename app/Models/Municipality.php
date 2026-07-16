<?php

namespace App\Models;

use Database\Factories\MunicipalityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Municipality extends Model
{
    /** @use HasFactory<MunicipalityFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'state',
        'cnpj',
        'ibge_code',
    ];

    /** @return array<string, string> */
    public static function states(): array
    {
        return array_combine(
            ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'],
            ['Acre', 'Alagoas', 'Amapá', 'Amazonas', 'Bahia', 'Ceará', 'Distrito Federal', 'Espírito Santo', 'Goiás', 'Maranhão', 'Mato Grosso', 'Mato Grosso do Sul', 'Minas Gerais', 'Pará', 'Paraíba', 'Paraná', 'Pernambuco', 'Piauí', 'Rio de Janeiro', 'Rio Grande do Norte', 'Rio Grande do Sul', 'Rondônia', 'Roraima', 'Santa Catarina', 'São Paulo', 'Sergipe', 'Tocantins'],
        );
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot([
                'role',
                'notify_in_app',
                'notify_email',
                'notify_deadlines',
                'notify_integrity',
            ])
            ->withTimestamps();
    }

    public function alertSetting(): HasOne
    {
        return $this->hasOne(MunicipalityAlertSetting::class);
    }

    public function integrityAlerts(): HasMany
    {
        return $this->hasMany(IntegrityAlert::class);
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(ParliamentaryAmendment::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(MunicipalityInvitation::class);
    }

    public function documentTypes(): HasMany
    {
        return $this->hasMany(DocumentType::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AmendmentDocument::class);
    }

    public function executionStages(): HasMany
    {
        return $this->hasMany(ExecutionStage::class);
    }

    public function financialCommitments(): HasMany
    {
        return $this->hasMany(FinancialCommitment::class);
    }

    public function financialPayments(): HasMany
    {
        return $this->hasMany(FinancialPayment::class);
    }

    public function scopeComplete(Builder $query): Builder
    {
        return $query
            ->whereNotNull('cnpj')
            ->whereNotNull('ibge_code')
            ->where('name', '!=', '')
            ->where('state', '!=', '');
    }

    public function hasCompleteProfile(): bool
    {
        return filled($this->name)
            && filled($this->state)
            && filled($this->cnpj)
            && filled($this->ibge_code);
    }
}
