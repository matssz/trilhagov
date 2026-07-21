<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class MunicipalDocumentTemplate extends Model
{
    protected $fillable = [
        'municipality_id', 'created_by', 'based_on_id', 'document_type', 'name',
        'prefix', 'version', 'subject_template', 'body_template', 'is_active',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Modelos municipais versionados não podem ser excluídos.'));
    }

    public function typeLabel(): string
    {
        return MunicipalOfficialDocument::types()[$this->document_type] ?? $this->document_type;
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'based_on_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(MunicipalOfficialDocument::class);
    }
}
