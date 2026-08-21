<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MunicipalNormativeInstrument extends Model
{
    protected $fillable = [
        'municipality_id', 'municipal_regulatory_profile_id', 'created_by',
        'type', 'title', 'reference', 'url', 'enacted_at', 'effective_from',
        'effective_until', 'notes', 'uploaded_by', 'original_name',
        'storage_path', 'mime_type', 'size_bytes',
    ];

    protected function casts(): array
    {
        return [
            'enacted_at' => 'date',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'size_bytes' => 'integer',
        ];
    }

    public static function types(): array
    {
        return [
            'organic_law' => 'Lei Orgânica Municipal',
            'internal_rules' => 'Regimento Interno da Câmara',
            'ppa' => 'Plano Plurianual (PPA)',
            'ldo' => 'Lei de Diretrizes Orçamentárias (LDO)',
            'loa' => 'Lei Orçamentária Anual (LOA)',
            'regulation' => 'Decreto ou regulamentação',
            'sectoral_plan' => 'Plano setorial',
            'other' => 'Outro instrumento',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MunicipalRegulatoryProfile::class, 'municipal_regulatory_profile_id');
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function typeLabel(): string
    {
        return self::types()[$this->type] ?? $this->type;
    }

    public function hasFile(): bool
    {
        return filled($this->storage_path);
    }

    public function formattedSize(): string
    {
        if ($this->size_bytes === null) {
            return '';
        }

        if ($this->size_bytes < 1024 * 1024) {
            return number_format(max(1, (int) ceil($this->size_bytes / 1024)), 0, ',', '.').' KB';
        }

        return number_format($this->size_bytes / (1024 * 1024), 1, ',', '.').' MB';
    }
}
