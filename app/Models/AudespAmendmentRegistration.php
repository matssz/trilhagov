<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudespAmendmentRegistration extends Model
{
    public const SCHEMA_VERSION = '2026_A';

    public const SCHEMA_PUBLISHED_AT = '2026-04-22';

    protected $fillable = [
        'municipality_id',
        'created_by',
        'scope',
        'amendment_type',
        'legal_basis',
        'proponent_name',
        'amendment_number',
        'amendment_year',
        'object',
        'purpose',
        'government_function',
        'government_subfunctions',
        'destination',
        'bank_account_opened',
        'application_code',
        'prior_balance_reclassified',
        'reclassification_reference',
        'reclassified_at',
        'prepared_at',
        'last_previewed_at',
        'preview_count',
    ];

    protected function casts(): array
    {
        return [
            'amendment_type' => 'integer',
            'amendment_year' => 'integer',
            'government_subfunctions' => 'array',
            'bank_account_opened' => 'boolean',
            'prior_balance_reclassified' => 'boolean',
            'reclassified_at' => 'date',
            'prepared_at' => 'datetime',
            'last_previewed_at' => 'datetime',
            'preview_count' => 'integer',
        ];
    }

    /** @return array<int, string> */
    public static function amendmentTypes(): array
    {
        return [
            1 => 'Individual (PIX)',
            2 => 'Individual (finalidade definida)',
            3 => 'Bancada ou bloco',
            4 => 'Relator',
            5 => 'Tipo 5 previsto no XSD',
        ];
    }

    /** @return array<string, string> */
    public static function legalBases(): array
    {
        return array_combine(
            ['Lei', 'Decreto', 'Resolução', 'Portaria'],
            ['Lei', 'Decreto', 'Resolução', 'Portaria'],
        );
    }

    /** @return array<string, string> */
    public static function destinations(): array
    {
        return ['C' => 'Custeio', 'I' => 'Investimento'];
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
