<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MunicipalReportDispatchEvent extends Model
{
    protected $fillable = [
        'municipality_id', 'municipal_report_dispatch_id', 'created_by', 'type',
        'occurred_at', 'protocol_number', 'message', 'evidence_original_name',
        'evidence_storage_path', 'evidence_mime_type', 'evidence_size_bytes',
        'evidence_sha256', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'evidence_size_bytes' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Eventos de remessa não podem ser alterados.'));
        static::deleting(fn () => throw new LogicException('Eventos de remessa não podem ser excluídos.'));
    }

    public static function labels(): array
    {
        return [
            'prepared' => 'Remessa preparada',
            'sent' => 'Envio protocolado',
            'acknowledged' => 'Recebimento confirmado',
            'rejected' => 'Documento devolvido',
            'cancelled' => 'Preparação cancelada',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->type] ?? $this->type;
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(MunicipalReportDispatch::class, 'municipal_report_dispatch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
