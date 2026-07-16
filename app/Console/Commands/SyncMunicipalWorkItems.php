<?php

namespace App\Console\Commands;

use App\Models\Municipality;
use App\Services\MunicipalWorkItemService;
use Illuminate\Console\Command;

class SyncMunicipalWorkItems extends Command
{
    protected $signature = 'work-items:sync {--municipality= : ID do município que será atualizado}';

    protected $description = 'Atualiza as próximas ações operacionais dos municípios';

    public function handle(MunicipalWorkItemService $service): int
    {
        $query = Municipality::query()->complete();
        if ($this->option('municipality')) {
            $query->whereKey((int) $this->option('municipality'));
        }

        $municipalities = $query->get();
        foreach ($municipalities as $municipality) {
            $stats = $service->synchronize($municipality);
            $this->line(sprintf(
                '%s: %d ativa(s), %d criada(s), %d reaberta(s), %d resolvida(s).',
                $municipality->name,
                $stats['active'],
                $stats['created'],
                $stats['reopened'],
                $stats['completed'],
            ));
        }

        return self::SUCCESS;
    }
}
