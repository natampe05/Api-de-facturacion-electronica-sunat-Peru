<?php

namespace App\Jobs;

use App\Services\PendingSunatOrderService;
use App\Services\SunatOrderSender;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSunatOrder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 75;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $orderId)
    {
        $this->onQueue((string) config('sunat_worker.queue', 'sunat'));
    }

    public function uniqueId(): string
    {
        return $this->orderId;
    }

    public function handle(SunatOrderSender $sender, PendingSunatOrderService $pending): void
    {
        try {
            $result = $sender->send($this->orderId);

            if ($result['terminal']) {
                $pending->complete($this->orderId);
                Log::info('Worker SUNAT completo el comprobante.', [
                    'orden_id' => $this->orderId,
                    'estado' => $result['state'],
                    'aceptado' => $result['accepted'],
                ]);

                return;
            }

            $pending->release($this->orderId, $result['message']);
            Log::warning('Worker SUNAT programo un nuevo intento.', [
                'orden_id' => $this->orderId,
                'estado' => $result['state'],
                'http_status' => $result['status'],
                'mensaje' => $result['message'],
            ]);
        } catch (Throwable $exception) {
            $pending->release($this->orderId, $exception->getMessage());
            Log::error('Worker SUNAT no pudo procesar el comprobante.', [
                'orden_id' => $this->orderId,
                'exception' => $exception,
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(PendingSunatOrderService::class)->release(
            $this->orderId,
            $exception?->getMessage() ?? 'El worker SUNAT termino inesperadamente.'
        );
    }
}
