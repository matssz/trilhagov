<?php

namespace App\Console\Commands;

use App\Models\Municipality;
use App\Services\IntegrityAlertProcessor;
use Illuminate\Console\Command;

class ProcessIntegrityAlerts extends Command
{
    protected $signature = 'alerts:process {--municipality= : ID do município a processar}';

    protected $description = 'Detecta pendências e envia os alertas ainda não entregues';

    public function handle(IntegrityAlertProcessor $processor): int
    {
        $municipality = filled($this->option('municipality'))
            ? Municipality::query()->findOrFail((int) $this->option('municipality'))
            : null;
        $stats = $processor->process($municipality);

        $this->info(sprintf(
            '%d município(s), %d alerta(s) aberto(s), %d envio(s), %d falha(s).',
            $stats['municipalities'],
            $stats['open'],
            $stats['sent'],
            $stats['failed'],
        ));

        return $stats['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
