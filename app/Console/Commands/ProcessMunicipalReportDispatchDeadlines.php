<?php

namespace App\Console\Commands;

use App\Models\Municipality;
use App\Services\MunicipalReportDispatchDeadlineProcessor;
use Illuminate\Console\Command;

class ProcessMunicipalReportDispatchDeadlines extends Command
{
    protected $signature = 'report-dispatches:process {--municipality= : ID do município}';

    protected $description = 'Envia alertas de prazo das remessas institucionais municipais';

    public function handle(MunicipalReportDispatchDeadlineProcessor $processor): int
    {
        $municipality = $this->option('municipality')
            ? Municipality::query()->findOrFail((int) $this->option('municipality'))
            : null;
        $stats = $processor->process($municipality);
        $this->line(sprintf(
            '%d remessa(s) no prazo de alerta; %d aviso(s) enviado(s); %d falha(s).',
            $stats['dispatches'],
            $stats['sent'],
            $stats['failed'],
        ));

        return self::SUCCESS;
    }
}
