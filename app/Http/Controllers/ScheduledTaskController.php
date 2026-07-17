<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class ScheduledTaskController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $expectedToken = (string) config('services.scheduler.token');
        $providedToken = (string) $request->bearerToken();

        abort_if(
            $expectedToken === '' || ! hash_equals($expectedToken, $providedToken),
            403,
        );

        $exitCode = Artisan::call('schedule:run', ['--no-interaction' => true]);

        Log::info('Agendamento externo processado.', ['exit_code' => $exitCode]);

        return response()->json([
            'status' => $exitCode === 0 ? 'ok' : 'error',
            'exit_code' => $exitCode,
            'processed_at' => now()->toIso8601String(),
        ], $exitCode === 0 ? 200 : 500);
    }
}
