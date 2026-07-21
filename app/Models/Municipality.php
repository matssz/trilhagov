<?php

namespace App\Models;

use Database\Factories\MunicipalityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Municipality extends Model
{
    /** @use HasFactory<MunicipalityFactory> */
    use HasFactory;

    private const SAO_PAULO_CAPITAL_IBGE_CODE = '3550308';

    protected $fillable = [
        'name',
        'state',
        'cnpj',
        'ibge_code',
        'transparency_enabled',
        'transparency_slug',
        'transparency_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'transparency_enabled' => 'boolean',
            'transparency_updated_at' => 'datetime',
        ];
    }

    /** @return array<string, string> */
    public static function states(): array
    {
        return array_combine(
            ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'],
            ['Acre', 'Alagoas', 'Amapá', 'Amazonas', 'Bahia', 'Ceará', 'Distrito Federal', 'Espírito Santo', 'Goiás', 'Maranhão', 'Mato Grosso', 'Mato Grosso do Sul', 'Minas Gerais', 'Pará', 'Paraíba', 'Paraná', 'Pernambuco', 'Piauí', 'Rio de Janeiro', 'Rio Grande do Norte', 'Rio Grande do Sul', 'Rondônia', 'Roraima', 'Santa Catarina', 'São Paulo', 'Sergipe', 'Tocantins'],
        );
    }

    public function supportsTcespAudesp(): bool
    {
        return $this->state === 'SP'
            && (string) $this->ibge_code !== self::SAO_PAULO_CAPITAL_IBGE_CODE;
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

    public function financialLiquidations(): HasMany
    {
        return $this->hasMany(FinancialLiquidation::class);
    }

    public function audespAmendmentRegistrations(): HasMany
    {
        return $this->hasMany(AudespAmendmentRegistration::class);
    }

    public function audespHomologationBatches(): HasMany
    {
        return $this->hasMany(AudespHomologationBatch::class);
    }

    public function audespHomologationItems(): HasMany
    {
        return $this->hasMany(AudespHomologationItem::class);
    }

    public function accountabilityProcesses(): HasMany
    {
        return $this->hasMany(AccountabilityProcess::class);
    }

    public function accountabilityRequirements(): HasMany
    {
        return $this->hasMany(AccountabilityRequirement::class);
    }

    public function accountabilityDiligences(): HasMany
    {
        return $this->hasMany(AccountabilityDiligence::class);
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable')
            ->latest('created_at')
            ->latest('id');
    }

    public function externalDataSyncs(): HasMany
    {
        return $this->hasMany(ExternalDataSync::class);
    }

    public function externalAmendmentCandidates(): HasMany
    {
        return $this->hasMany(ExternalAmendmentCandidate::class);
    }

    public function externalFinancialReconciliations(): HasMany
    {
        return $this->hasMany(ExternalFinancialReconciliation::class);
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(MunicipalWorkItem::class);
    }

    public function amendmentImportBatches(): HasMany
    {
        return $this->hasMany(AmendmentImportBatch::class);
    }

    public function complianceReviews(): HasMany
    {
        return $this->hasMany(AmendmentComplianceReview::class);
    }

    public function municipalWorkPlans(): HasMany
    {
        return $this->hasMany(MunicipalWorkPlan::class);
    }

    public function technicalImpediments(): HasMany
    {
        return $this->hasMany(TechnicalImpediment::class);
    }

    public function technicalDiligences(): HasMany
    {
        return $this->hasMany(TechnicalDiligence::class);
    }

    public function remappings(): HasMany
    {
        return $this->hasMany(AmendmentRemapping::class);
    }

    public function municipalAdmissibilityReviews(): HasMany
    {
        return $this->hasMany(MunicipalAdmissibilityReview::class);
    }

    public function regulatoryProfiles(): HasMany
    {
        return $this->hasMany(MunicipalRegulatoryProfile::class);
    }

    public function normativeInstruments(): HasMany
    {
        return $this->hasMany(MunicipalNormativeInstrument::class);
    }

    public function governanceReports(): HasMany
    {
        return $this->hasMany(MunicipalGovernanceReport::class);
    }

    public function reportDispatches(): HasMany
    {
        return $this->hasMany(MunicipalReportDispatch::class);
    }

    public function documentTemplates(): HasMany
    {
        return $this->hasMany(MunicipalDocumentTemplate::class);
    }

    public function officialDocuments(): HasMany
    {
        return $this->hasMany(MunicipalOfficialDocument::class);
    }

    public function internalControlReviews(): HasMany
    {
        return $this->hasMany(MunicipalInternalControlReview::class);
    }

    public function internalControlActions(): HasMany
    {
        return $this->hasMany(MunicipalInternalControlAction::class);
    }

    public function auditPlans(): HasMany
    {
        return $this->hasMany(MunicipalAuditPlan::class);
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
