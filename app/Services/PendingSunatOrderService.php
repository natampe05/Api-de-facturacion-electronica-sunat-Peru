<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PendingSunatOrderService
{
    /**
     * Reserve a batch atomically. The same lease columns are shared with the
     * browser fallback, so browser terminals and server workers cannot claim
     * the same order at the same time.
     *
     * @return array<int, string>
     */
    public function claim(?int $requestedLimit = null): array
    {
        $limit = min(max($requestedLimit ?? (int) config('sunat_worker.batch_size', 10), 1), 50);
        $leaseSeconds = max((int) config('sunat_worker.lease_seconds', 300), 30);
        $staleProcessingSeconds = max((int) config('sunat_worker.stale_processing_seconds', 300), 30);
        $maxAttempts = max((int) config('sunat_worker.max_attempts', 10), 1);
        $now = now('UTC');

        return DB::transaction(function () use ($limit, $leaseSeconds, $staleProcessingSeconds, $maxAttempts, $now) {
            $ids = DB::table('ordenes')
                ->select('id')
                ->whereIn('estado', ['pagado', 'pagada'])
                ->whereIn('tipo_comprobante', ['boleta', 'factura'])
                ->where('sunat_retry_attempts', '<', $maxAttempts)
                ->whereRaw(
                    "coalesce(sunat_estado, 'pendiente') in (?, ?, ?)",
                    ['pendiente', 'error', 'procesando']
                )
                ->where(function ($query) use ($staleProcessingSeconds, $now) {
                    $query->whereRaw("coalesce(sunat_estado, 'pendiente') <> 'procesando'")
                        ->orWhere('updated_at', '<=', $now->copy()->subSeconds($staleProcessingSeconds));
                })
                ->where(function ($query) use ($now) {
                    $query->whereNull('sunat_retry_after')
                        ->orWhere('sunat_retry_after', '<=', $now);
                })
                ->where(function ($query) use ($leaseSeconds, $now) {
                    $query->whereNull('sunat_processing_at')
                        ->orWhere('sunat_processing_at', '<=', $now->copy()->subSeconds($leaseSeconds));
                })
                ->orderBy('created_at')
                ->orderBy('id')
                ->lock('for update skip locked')
                ->limit($limit)
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();

            if ($ids === []) {
                return [];
            }

            DB::table('ordenes')
                ->whereIn('id', $ids)
                ->update([
                    'sunat_processing_at' => $now,
                    'sunat_retry_after' => null,
                    'sunat_retry_attempts' => DB::raw('coalesce(sunat_retry_attempts, 0) + 1'),
                    'sunat_retry_last_error' => null,
                ]);

            return $ids;
        }, 3);
    }

    public function complete(string $orderId): void
    {
        DB::table('ordenes')
            ->where('id', $orderId)
            ->update([
                'sunat_processing_at' => null,
                'sunat_retry_after' => null,
                'sunat_retry_last_error' => null,
            ]);
    }

    public function release(string $orderId, string $error): void
    {
        $order = DB::table('ordenes')
            ->select('sunat_estado', 'sunat_retry_attempts')
            ->where('id', $orderId)
            ->first();

        if (! $order) {
            return;
        }

        $attempt = max((int) ($order->sunat_retry_attempts ?? 1), 1);
        $maxAttempts = max((int) config('sunat_worker.max_attempts', 10), 1);
        $exhausted = $attempt >= $maxAttempts;
        $message = mb_substr(trim($error) ?: 'Error SUNAT sin detalle.', 0, 1000);

        if ($exhausted) {
            $message = mb_substr("Reintentos automaticos agotados ({$attempt}/{$maxAttempts}). {$message}", 0, 1000);
        }

        $updates = [
            'sunat_processing_at' => null,
            'sunat_retry_after' => $exhausted
                ? null
                : now('UTC')->addSeconds($this->delayForAttempt($attempt)),
            'sunat_retry_last_error' => $message,
        ];

        // If the process died after marking the order as processing, make it
        // eligible again without changing explicit SUNAT rejections.
        if ($order->sunat_estado === 'procesando') {
            $updates['sunat_estado'] = 'error';
            $updates['sunat_mensaje'] = $message;
        }

        DB::table('ordenes')->where('id', $orderId)->update($updates);
    }

    public function delayForAttempt(int $attempt): int
    {
        $backoff = config('sunat_worker.backoff_seconds', [60, 180, 600, 1800, 3600, 10800]);
        $backoff = array_values(array_filter(array_map('intval', (array) $backoff), fn (int $seconds) => $seconds > 0));

        if ($backoff === []) {
            return 180;
        }

        return $backoff[min(max($attempt - 1, 0), count($backoff) - 1)];
    }
}
