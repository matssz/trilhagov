<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PrepareProductionDatabase extends Command
{
    protected $signature = 'db:prepare-production';

    protected $description = 'Prepare the isolated PostgreSQL schema used in production';

    public function handle(): int
    {
        if (config('database.default') !== 'pgsql') {
            $this->components->info('No PostgreSQL preparation was required.');

            return self::SUCCESS;
        }

        $schema = (string) config('database.connections.pgsql.search_path');

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema)) {
            throw new RuntimeException('The PostgreSQL schema name is invalid.');
        }

        DB::statement(sprintf('CREATE SCHEMA IF NOT EXISTS "%s"', $schema));
        $this->components->info("PostgreSQL schema ready: {$schema}");

        return self::SUCCESS;
    }
}
