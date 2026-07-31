<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MunicipalInstitution extends Model
{
    public const TYPE_DEPARTMENT = 'department';

    public const TYPE_EXECUTING_UNIT = 'executing_unit';

    public const TYPE_COUNCILOR = 'councilor';

    public const TYPE_BENEFICIARY = 'beneficiary';

    public const TYPE_SUPPLIER = 'supplier';

    public const TYPE_INSPECTOR = 'inspector';

    protected $fillable = [
        'municipality_id',
        'created_by',
        'updated_by',
        'type',
        'name',
        'legal_name',
        'document',
        'party',
        'department',
        'role_title',
        'email',
        'phone',
        'address',
        'city',
        'state',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return array<string, string> */
    public static function types(): array
    {
        return [
            self::TYPE_DEPARTMENT => 'Secretaria / orgao',
            self::TYPE_EXECUTING_UNIT => 'Unidade executora',
            self::TYPE_COUNCILOR => 'Vereador',
            self::TYPE_BENEFICIARY => 'Beneficiario',
            self::TYPE_SUPPLIER => 'Fornecedor',
            self::TYPE_INSPECTOR => 'Fiscal / responsavel',
        ];
    }

    public function typeLabel(): string
    {
        return self::types()[$this->type] ?? $this->type;
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
