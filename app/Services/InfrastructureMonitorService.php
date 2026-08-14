<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class InfrastructureMonitorService
{
    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $checks = [
            $this->databaseCheck(),
            $this->storageCheck(),
            $this->cacheCheck(),
            $this->queueCheck(),
            $this->schedulerCheck(),
            $this->logsCheck(),
            $this->environmentCheck(),
        ];

        return [
            'generated_at' => now(),
            'status' => $this->overallStatus($checks),
            'checks' => $checks,
            'metrics' => $this->metrics(),
            'deployment' => $this->deployment(),
            'recent_errors' => $this->recentErrors(),
        ];
    }

    /** @return array<string, mixed> */
    private function databaseCheck(): array
    {
        $startedAt = microtime(true);

        try {
            DB::select('select 1');

            return $this->check(
                'database',
                'Banco de dados',
                'ok',
                'Conexão ativa',
                'O Laravel conseguiu consultar o banco de dados.',
                'Nenhuma ação necessária.',
                ['latency_ms' => $this->elapsed($startedAt), 'connection' => config('database.default')]
            );
        } catch (Throwable $exception) {
            return $this->check(
                'database',
                'Banco de dados',
                'critical',
                'Falha de conexão',
                'O sistema não conseguiu consultar o banco. Login, emendas e documentos podem falhar.',
                'Verifique DB_URL, Supabase e logs do deploy.',
                ['error' => Str::limit($exception->getMessage(), 160)]
            );
        }
    }

    /** @return array<string, mixed> */
    private function storageCheck(): array
    {
        $disk = (string) config('filesystems.default');
        $path = 'health-checks/trilhagov-'.Str::uuid().'.txt';

        try {
            Storage::disk($disk)->put($path, now()->toIso8601String());
            $exists = Storage::disk($disk)->exists($path);
            Storage::disk($disk)->delete($path);

            return $this->check(
                'storage',
                'Storage de documentos',
                $exists ? 'ok' : 'attention',
                $exists ? 'Leitura e escrita ativas' : 'Escrita sem confirmação de leitura',
                $exists ? 'Uploads, PDFs e pacotes podem usar o disco configurado.' : 'O arquivo de teste foi enviado, mas não foi confirmado no disco.',
                $exists ? 'Nenhuma ação necessária.' : 'Revise permissões do bucket/disco e variáveis AWS/Supabase.',
                ['disk' => $disk]
            );
        } catch (Throwable $exception) {
            return $this->check(
                'storage',
                'Storage de documentos',
                'critical',
                'Falha no storage',
                'Uploads, downloads e geração de pacotes podem ficar indisponíveis.',
                'Verifique bucket, endpoint, região e credenciais do storage.',
                ['disk' => $disk, 'error' => Str::limit($exception->getMessage(), 160)]
            );
        }
    }

    /** @return array<string, mixed> */
    private function cacheCheck(): array
    {
        $key = 'infra-monitor:'.Str::uuid();

        try {
            Cache::put($key, 'ok', now()->addMinute());
            $ok = Cache::get($key) === 'ok';
            Cache::forget($key);

            return $this->check(
                'cache',
                'Cache',
                $ok ? 'ok' : 'attention',
                $ok ? 'Operacional' : 'Cache sem leitura confirmada',
                $ok ? 'O cache aceita gravação e leitura.' : 'O cache recebeu a escrita, mas não retornou o valor esperado.',
                $ok ? 'Nenhuma ação necessária.' : 'Revise CACHE_STORE e permissões do driver.',
                ['driver' => config('cache.default')]
            );
        } catch (Throwable $exception) {
            return $this->check(
                'cache',
                'Cache',
                'attention',
                'Cache indisponível',
                'O sistema ainda pode funcionar, mas ficará mais lento e com mais carga no banco.',
                'Revise CACHE_STORE. Em produção, prefira database ou Redis.',
                ['driver' => config('cache.default'), 'error' => Str::limit($exception->getMessage(), 160)]
            );
        }
    }

    /** @return array<string, mixed> */
    private function queueCheck(): array
    {
        $connection = (string) config('queue.default');
        $jobsTable = (string) config('queue.connections.database.table', 'jobs');
        $failedTable = (string) config('queue.failed.table', 'failed_jobs');

        try {
            $pendingJobs = Schema::hasTable($jobsTable) ? DB::table($jobsTable)->count() : null;
            $failedJobs = Schema::hasTable($failedTable) ? DB::table($failedTable)->count() : null;

            $status = 'ok';
            $summary = 'Fila operacional';
            $description = 'A configuração de fila está carregada.';
            $action = 'Acompanhe crescimento de jobs pendentes e falhas.';

            if ($connection === 'sync') {
                $status = 'attention';
                $summary = 'Fila síncrona';
                $description = 'Jobs rodam durante a requisição. Funciona no piloto, mas pode gerar lentidão em rotinas pesadas.';
                $action = 'Para produção com volume, use fila database/Redis e worker dedicado.';
            } elseif ($pendingJobs === null) {
                $status = 'attention';
                $summary = 'Tabela de jobs não localizada';
                $description = 'A conexão usa fila assíncrona, mas a tabela de jobs não foi encontrada.';
                $action = 'Rode as migrations da fila ou ajuste QUEUE_CONNECTION.';
            } elseif (($failedJobs ?? 0) > 0) {
                $status = 'attention';
                $summary = 'Há jobs com falha';
                $description = 'Existem rotinas assíncronas que falharam e precisam de revisão.';
                $action = 'Abra os logs, corrija a causa e reprocessar quando aplicável.';
            }

            return $this->check('queue', 'Fila e jobs', $status, $summary, $description, $action, [
                'connection' => $connection,
                'pending_jobs' => $pendingJobs,
                'failed_jobs' => $failedJobs,
            ]);
        } catch (Throwable $exception) {
            return $this->check(
                'queue',
                'Fila e jobs',
                'attention',
                'Não foi possível ler a fila',
                'A tela não conseguiu consultar as tabelas de jobs.',
                'Revise migrations, permissões e conexão do banco.',
                ['connection' => $connection, 'error' => Str::limit($exception->getMessage(), 160)]
            );
        }
    }

    /** @return array<string, mixed> */
    private function schedulerCheck(): array
    {
        $hasToken = filled(config('services.scheduler.token'));

        return $this->check(
            'scheduler',
            'Agendador',
            $hasToken ? 'ok' : 'attention',
            $hasToken ? 'Token configurado' : 'Token não configurado',
            $hasToken
                ? 'A rota interna do agendador pode receber chamadas protegidas.'
                : 'Sem token, a rota interna de agendamento fica bloqueada e rotinas automáticas podem não rodar.',
            $hasToken
                ? 'Confirme no Render/Cron se a chamada periódica está ativa.'
                : 'Configure SCHEDULER_TOKEN e um cron externo chamando /api/internal/scheduler.',
            ['endpoint' => url('/api/internal/scheduler')]
        );
    }

    /** @return array<string, mixed> */
    private function logsCheck(): array
    {
        $path = storage_path('logs/laravel.log');

        if (! is_file($path)) {
            return $this->check(
                'logs',
                'Logs da aplicação',
                'attention',
                'Log local não encontrado',
                'Ainda não há arquivo laravel.log disponível neste ambiente.',
                'Depois de rodar o sistema, confirme se logs estão sendo gravados.',
                ['path' => 'storage/logs/laravel.log']
            );
        }

        $size = filesize($path) ?: 0;
        $recentErrors = count($this->recentErrors());

        return $this->check(
            'logs',
            'Logs da aplicação',
            $recentErrors > 0 ? 'attention' : 'ok',
            $recentErrors > 0 ? 'Erros recentes detectados' : 'Sem erros recentes no recorte lido',
            $recentErrors > 0
                ? 'O log contém exceções recentes no trecho analisado.'
                : 'O arquivo de log existe e o recorte recente não mostra erro crítico.',
            $recentErrors > 0
                ? 'Analise as rotas e mensagens listadas abaixo.'
                : 'Nenhuma ação necessária.',
            ['size' => $this->humanBytes($size), 'recent_errors' => $recentErrors]
        );
    }

    /** @return array<string, mixed> */
    private function environmentCheck(): array
    {
        $debug = (bool) config('app.debug');
        $production = config('app.env') === 'production';

        return $this->check(
            'environment',
            'Ambiente',
            $production && $debug ? 'critical' : 'ok',
            $production && $debug ? 'Debug ligado em produção' : 'Configuração segura',
            $production && $debug
                ? 'APP_DEBUG ligado pode expor detalhes técnicos para usuários.'
                : 'Ambiente e debug estão coerentes para operação.',
            $production && $debug
                ? 'Desative APP_DEBUG no Render imediatamente.'
                : 'Nenhuma ação necessária.',
            ['env' => config('app.env'), 'debug' => $debug ? 'ativo' : 'desligado']
        );
    }

    /** @param array<int, array<string, mixed>> $checks */
    private function overallStatus(array $checks): string
    {
        if (collect($checks)->contains(fn (array $check) => $check['status'] === 'critical')) {
            return 'critical';
        }

        if (collect($checks)->contains(fn (array $check) => $check['status'] === 'attention')) {
            return 'attention';
        }

        return 'ok';
    }

    /** @return array<string, mixed> */
    private function metrics(): array
    {
        return [
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'database' => (string) config('database.default'),
            'queue' => (string) config('queue.default'),
            'cache' => (string) config('cache.default'),
            'storage' => (string) config('filesystems.default'),
            'timezone' => (string) config('app.timezone'),
        ];
    }

    /** @return array<string, mixed> */
    private function deployment(): array
    {
        return [
            'app_url' => config('app.url'),
            'service_id' => env('RENDER_SERVICE_ID') ?: env('RENDER_SERVICE_NAME') ?: 'Não informado',
            'commit' => env('RENDER_GIT_COMMIT') ? Str::limit((string) env('RENDER_GIT_COMMIT'), 12, '') : 'Não informado',
            'branch' => env('RENDER_GIT_BRANCH') ?: 'Não informado',
            'deployed_at' => env('RENDER_DEPLOY_ID') ?: 'Não informado',
        ];
    }

    /** @return array<int, array{level: string, message: string}> */
    private function recentErrors(): array
    {
        $path = storage_path('logs/laravel.log');

        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $content = $this->tail($path, 160000);
        preg_match_all('/^\[.+?\]\s+\w+\.(ERROR|CRITICAL|ALERT|EMERGENCY):\s+(.+)$/m', $content, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->take(-8)
            ->map(fn (array $match) => [
                'level' => $match[1],
                'message' => Str::limit($match[2], 220),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function check(string $key, string $title, string $status, string $summary, string $description, string $action, array $meta = []): array
    {
        return compact('key', 'title', 'status', 'summary', 'description', 'action', 'meta');
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function tail(string $path, int $bytes): string
    {
        $size = filesize($path) ?: 0;
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        fseek($handle, max(0, $size - $bytes));
        $content = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $content;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1, ',', '.').' KB';
        }

        return number_format($bytes / 1048576, 1, ',', '.').' MB';
    }
}
