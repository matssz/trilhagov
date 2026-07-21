<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MunicipalOfficialDocumentEvent extends Model
{
    protected $fillable = [
        'municipality_id', 'municipal_official_document_id', 'created_by', 'type',
        'occurred_at', 'protocol_number', 'message', 'evidence_original_name',
        'evidence_storage_path', 'evidence_mime_type', 'evidence_size_bytes',
        'evidence_sha256', 'metadata',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'evidence_size_bytes' => 'integer', 'metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Eventos de documento oficial não podem ser alterados.'));
        static::deleting(fn () => throw new LogicException('Eventos de documento oficial não podem ser excluídos.'));
    }

    public static function labels(): array
    {
        return [
            'drafted' => 'Minuta gerada', 'updated' => 'Minuta revisada', 'issued' => 'Documento emitido',
            'sent' => 'Envio protocolado', 'acknowledged' => 'Recebimento confirmado',
            'rejected' => 'Documento devolvido', 'cancelled' => 'Minuta cancelada',
            'revision_created' => 'Nova versão preparada',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->type] ?? $this->type;
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(MunicipalOfficialDocument::class, 'municipal_official_document_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
