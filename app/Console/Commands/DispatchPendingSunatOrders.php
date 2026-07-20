<?php

namespace App\Console\Commands;

use App\Jobs\ProcessSunatOrder;
use App\Services\PendingSunatOrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchPendingSunatOrders extends Command
{
    protected $signature = 'sunat:dispatch-pending {--batch= : Cantidad maxima de comprobantes a reservar}';

    protected $description = 'Reserva comprobantes SUNAT pendientes y los envia a la cola independiente';

    public function handle(PendingSunatOrderService $pending): int
    {
        if (! config('sunat_worker.enabled', true)) {
            $this->components->info('El worker SUNAT esta deshabilitado.');

            return self::SUCCESS;
        }

        $requestedBatch = $this->option('batch');
        $orderIds = $pending->claim($requestedBatch !== null ? (int) $requestedBatch : null);
        $dispatched = 0;

        foreach ($orderIds as $orderId) {
            try {
                ProcessSunatOrder::dispatch($orderId);
                $dispatched++;
            } catch (Throwable $exception) {
                $pending->release($orderId, 'No se pudo publicar el trabajo SUNAT: '.$exception->getMessage());
                Log::error('No se pudo publicar un trabajo en la cola SUNAT.', [
                    'orden_id' => $orderId,
                    'exception' => $exception,
                ]);
            }
        }

        if ($dispatched > 0) {
            $this->components->info("{$dispatched} comprobante(s) enviado(s) a la cola SUNAT.");
        }

        return self::SUCCESS;
    }
}
