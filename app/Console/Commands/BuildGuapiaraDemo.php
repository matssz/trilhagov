<?php

namespace App\Console\Commands;

use Database\Seeders\GuapiaraDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class BuildGuapiaraDemo extends Command
{
    protected $signature = 'trilhagov:demo-guapiara {--force : Permite executar em producao sem confirmacao interativa}';

    protected $description = 'Cria ou atualiza a base demonstrativa comercial de Guapiara/SP.';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->warn('Voce esta em producao. Use --force somente quando quiser atualizar a demo comercial.');

            return self::FAILURE;
        }

        Artisan::call('db:seed', [
            '--class' => GuapiaraDemoSeeder::class,
            '--force' => true,
        ]);

        $this->line(Artisan::output());
        $this->info('Demo comercial pronta para apresentacao.');

        return self::SUCCESS;
    }
}
