<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SunatCorrelativeService
{
    public function reserveNumber(
        string $empresaId,
        string $documentType,
        string $series,
        int $startingNumber
    ): int {
        return DB::transaction(
            fn () => $this->reserveNumberWithinTransaction(
                $empresaId,
                $documentType,
                $series,
                $startingNumber
            ),
            3
        );
    }

    public function reserveForOrder(
        string $orderId,
        string $empresaId,
        string $documentType,
        string $series,
        int $startingNumber
    ): array {
        return DB::transaction(function () use ($orderId, $empresaId, $documentType, $series, $startingNumber) {
            $order = DB::table('ordenes')
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first();

            if (!$order || (string) $order->empresa_id !== $empresaId) {
                throw new \RuntimeException('La orden no existe o no pertenece a la empresa.');
            }

            if ($order->sunat_estado === 'enviado') {
                return [
                    'already_sent' => true,
                    'busy' => false,
                    'number' => (int) $order->comprobante_numero,
                    'series' => (string) $order->comprobante_serie,
                ];
            }

            if ($order->sunat_estado === 'procesando' && !empty($order->updated_at)) {
                $updatedAt = \Carbon\Carbon::parse($order->updated_at);
                if ($updatedAt->greaterThan(now('UTC')->subMinutes(5))) {
                    return [
                        'already_sent' => false,
                        'busy' => true,
                        'number' => (int) ($order->comprobante_numero ?? 0),
                        'series' => (string) ($order->comprobante_serie ?: $series),
                    ];
                }
            }

            if (!empty($order->comprobante_numero)) {
                DB::table('ordenes')->where('id', $orderId)->update([
                    'sunat_estado' => 'procesando',
                    'updated_at' => now('UTC')->toIso8601String(),
                ]);

                return [
                    'already_sent' => false,
                    'busy' => false,
                    'number' => (int) $order->comprobante_numero,
                    'series' => (string) ($order->comprobante_serie ?: $series),
                ];
            }

            $nextNumber = $this->reserveNumberWithinTransaction(
                $empresaId,
                $documentType,
                $series,
                $startingNumber
            );
            $timestamp = now('UTC')->toIso8601String();

            DB::table('ordenes')
                ->where('id', $orderId)
                ->update([
                    'comprobante_serie' => $series,
                    'comprobante_numero' => $nextNumber,
                    'sunat_estado' => 'procesando',
                    'updated_at' => $timestamp,
                ]);

            return [
                'already_sent' => false,
                'busy' => false,
                'number' => $nextNumber,
                'series' => $series,
            ];
        }, 3);
    }

    private function reserveNumberWithinTransaction(
        string $empresaId,
        string $documentType,
        string $series,
        int $startingNumber
    ): int {
        $counter = DB::table('sunat_correlativos')
            ->where('empresa_id', $empresaId)
            ->where('tipo_comprobante', $documentType)
            ->where('serie', $series)
            ->lockForUpdate()
            ->first();

        if (!$counter) {
            // MAX se usa una sola vez para inicializar datos históricos. Las
            // reservas siguientes dependen exclusivamente de la fila bloqueada.
            $historicalMaximum = (int) (DB::table('ordenes')
                ->where('empresa_id', $empresaId)
                ->where('tipo_comprobante', $documentType)
                ->where('comprobante_serie', $series)
                ->max('comprobante_numero') ?? 0);

            DB::table('sunat_correlativos')->insertOrIgnore([
                'empresa_id' => $empresaId,
                'tipo_comprobante' => $documentType,
                'serie' => $series,
                'ultimo_numero' => max($historicalMaximum, $startingNumber - 1),
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);

            $counter = DB::table('sunat_correlativos')
                ->where('empresa_id', $empresaId)
                ->where('tipo_comprobante', $documentType)
                ->where('serie', $series)
                ->lockForUpdate()
                ->first();
        }

        if (!$counter) {
            throw new \RuntimeException('No se pudo bloquear el correlativo SUNAT.');
        }

        $nextNumber = max((int) $counter->ultimo_numero + 1, $startingNumber);

        DB::table('sunat_correlativos')
            ->where('id', $counter->id)
            ->update([
                'ultimo_numero' => $nextNumber,
                'updated_at' => now('UTC'),
            ]);

        return $nextNumber;
    }
}
