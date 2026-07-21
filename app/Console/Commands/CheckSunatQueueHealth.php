<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckSunatQueueHealth extends Command
{
    protected $signature = 'sunat:check-health';

    protected $description = 'Detecta trabajos SUNAT agotados o una cola fiscal atrasada';

    public function handle(): int
    {
        if (! config('sunat_worker.enabled', true)) {
            return self::SUCCESS;
        }

        $maxAttempts = max((int) config('sunat_worker.max_attempts', 10), 1);
        $depthThreshold = max((int) config('sunat_worker.alert_queue_depth', 100), 1);
        $oldestThresholdMinutes = max((int) config('sunat_worker.alert_oldest_minutes', 15), 1);

        $pending = DB::table('ordenes')
            ->whereIn('estado', ['pagado', 'pagada'])
            ->whereIn('tipo_comprobante', ['boleta', 'factura'])
            ->whereRaw(
                "coalesce(sunat_estado, 'pendiente') in (?, ?, ?)",
                ['pendiente', 'error', 'procesando']
            );

        $queueDepth = (clone $pending)->count();
        $oldestCreatedAt = (clone $pending)->min('created_at');
        $exhaustedIds = (clone $pending)
            ->where('sunat_retry_attempts', '>=', $maxAttempts)
            ->orderBy('updated_at')
            ->limit(25)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $oldestMinutes = $oldestCreatedAt
            ? max((int) now('UTC')->diffInMinutes($oldestCreatedAt), 0)
            : 0;

        $context = [
            'queue_depth' => $queueDepth,
            'oldest_minutes' => $oldestMinutes,
            'max_attempts' => $maxAttempts,
            'exhausted_count_sample' => count($exhaustedIds),
            'exhausted_order_ids' => $exhaustedIds,
        ];

        if ($exhaustedIds !== []) {
            Log::critical('Existen trabajos SUNAT con reintentos agotados.', $context);
            $this->components->error('SUNAT tiene trabajos con reintentos agotados.');

            return self::FAILURE;
        }

        if ($queueDepth >= $depthThreshold || $oldestMinutes >= $oldestThresholdMinutes) {
            Log::warning('La cola SUNAT supera el umbral operativo.', $context);
            $this->components->warn('La cola SUNAT supera el umbral operativo.');
        }

        return self::SUCCESS;
    }
}

