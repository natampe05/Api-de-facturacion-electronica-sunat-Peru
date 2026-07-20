<?php

namespace App\Services;

use App\Http\Controllers\Api\FacturacionController;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class SunatOrderSender
{
    public function __construct(private readonly FacturacionController $controller) {}

    /**
     * @return array{terminal: bool, accepted: bool, state: string, message: string, status: int}
     */
    public function send(string $orderId): array
    {
        $response = $this->controller->procesarOrden($orderId, soloEnviar: true);
        $status = $response instanceof Response ? $response->getStatusCode() : 500;

        if ($response instanceof JsonResponse) {
            $payload = $response->getData(true);
        } elseif ($response instanceof Response) {
            $payload = json_decode((string) $response->getContent(), true) ?: [];
        } else {
            $payload = [];
        }

        $state = (string) ($payload['estado'] ?? (($payload['success'] ?? false) ? 'enviado' : 'error'));
        $accepted = (bool) ($payload['success'] ?? false)
            || in_array($state, ['enviado', 'aceptado_observaciones'], true);
        $terminal = $accepted || $state === 'rechazado';

        return [
            'terminal' => $terminal,
            'accepted' => $accepted,
            'state' => $state,
            'message' => (string) ($payload['message'] ?? $payload['mensaje'] ?? "Respuesta SUNAT HTTP {$status}"),
            'status' => $status,
        ];
    }
}
