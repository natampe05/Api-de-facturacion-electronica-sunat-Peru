<?php

namespace App\Console\Commands;

use App\Services\OperationsAlertService;
use App\Services\PostgresConnectionUsage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckSunatQueueHealth extends Command
{
    protected $signature = 'sunat:check-health';

    protected $description = 'Detecta trabajos SUNAT agotados, una cola fiscal atrasada o presión de conexiones';

    public function handle(
        OperationsAlertService $alerts,
        PostgresConnectionUsage $connectionUsage,
    ): int {
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

        $exitCode = self::SUCCESS;

        if ($exhaustedIds !== []) {
            Log::critical('Existen trabajos SUNAT con reintentos agotados.', $context);
            $alerts->notify(
                'sunat-exhausted',
                'critical',
                'SUNAT tiene trabajos con reintentos agotados',
                $context,
            );
            $this->components->error('SUNAT tiene trabajos con reintentos agotados.');
            $exitCode = self::FAILURE;
        }

        if ($exhaustedIds === [] && ($queueDepth >= $depthThreshold || $oldestMinutes >= $oldestThresholdMinutes)) {
            Log::warning('La cola SUNAT supera el umbral operativo.', $context);
            $alerts->notify(
                'sunat-queue-delayed',
                'warning',
                'La cola SUNAT supera el umbral operativo',
                $context,
            );
            $this->components->warn('La cola SUNAT supera el umbral operativo.');
        }

        $this->checkDatabaseConnections($alerts, $connectionUsage);
        $this->sendStagingReviewReminder($alerts);

        return $exitCode;
    }

    private function checkDatabaseConnections(
        OperationsAlertService $alerts,
        PostgresConnectionUsage $connectionUsage,
    ): void {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        try {
            $usage = $connectionUsage->measure();
            $percent = $usage['percent'];
            $warning = max((int) config('operations.db_connections_warning_percent', 70), 1);
            $critical = max((int) config('operations.db_connections_critical_percent', 85), $warning);

            if ($percent < $warning) {
                return;
            }

            $severity = $percent >= $critical ? 'critical' : 'warning';
            $context = [
                'conexiones_cliente_usadas' => $usage['used'],
                'conexiones_ejecutando_consultas' => $usage['active'],
                'conexiones_inactivas' => $usage['idle'],
                'conexiones_inactivas_en_transaccion' => $usage['idle_in_transaction'],
                'conexiones_utilizables' => $usage['usable'],
                'conexiones_reservadas' => $usage['reserved'],
                'conexiones_maximas_postgresql' => $usage['maximum'],
                'porcentaje_usado' => $percent.'%',
                'umbral_warning' => $warning.'%',
                'umbral_critical' => $critical.'%',
            ];

            Log::log($severity, 'El uso de conexiones PostgreSQL supera el umbral operativo.', $context);
            $alerts->notify(
                'database-connections-'.$severity,
                $severity,
                'PostgreSQL supera el umbral de conexiones',
                $context,
            );
        } catch (Throwable $exception) {
            Log::warning('No se pudo medir el uso de conexiones PostgreSQL.', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function sendStagingReviewReminder(OperationsAlertService $alerts): void
    {
        $reviewAt = trim((string) config('operations.staging_review_at'));

        if ($reviewAt === '') {
            return;
        }

        try {
            $reviewDate = CarbonImmutable::parse($reviewAt)->utc();
        } catch (Throwable $exception) {
            Log::warning('OPERATIONS_STAGING_REVIEW_AT no tiene una fecha válida.', [
                'value' => $reviewAt,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        if (now('UTC')->isBefore($reviewDate)) {
            return;
        }

        $alerts->notify(
            'staging-review-'.$reviewDate->format('YmdHi'),
            'info',
            'Es momento de revisar y retirar el staging temporal',
            [
                'fecha_programada_utc' => $reviewDate->toIso8601String(),
                'criterio' => 'retirar si los SLO se cumplieron durante una hora pico y no hubo errores ni duplicados SUNAT',
            ],
            60 * 24 * 365,
        );
    }
}
