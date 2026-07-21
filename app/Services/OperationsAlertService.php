<?php

namespace App\Services;

use App\Notifications\OperationsHealthAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class OperationsAlertService
{
    public function notify(
        string $incidentKey,
        string $severity,
        string $title,
        array $context = [],
        ?int $cooldownMinutes = null,
    ): bool {
        $recipient = trim((string) config('operations.alert_email'));

        if ($recipient === '') {
            Log::warning('No se envió una alerta operativa porque OPERATIONS_ALERT_EMAIL no está configurado.', [
                'incident_key' => $incidentKey,
                'alert_title' => $title,
            ]);

            return false;
        }

        $mailer = (string) config('mail.default', 'log');

        if (in_array($mailer, ['log', 'array'], true)) {
            Log::warning('La alerta operativa se registró, pero el transporte de correo no está activo.', [
                'incident_key' => $incidentKey,
                'alert_title' => $title,
                'mailer' => $mailer,
            ]);

            return false;
        }

        $cooldownMinutes ??= max((int) config('operations.alert_cooldown_minutes', 60), 1);
        $cacheKey = 'operations-alert:'.sha1($incidentKey);

        try {
            if (! Cache::add($cacheKey, now('UTC')->toIso8601String(), now()->addMinutes($cooldownMinutes))) {
                return false;
            }
        } catch (Throwable $exception) {
            Log::warning('El control anti-spam de alertas no estuvo disponible; se intentará enviar la alerta.', [
                'incident_key' => $incidentKey,
                'error' => $exception->getMessage(),
            ]);
        }

        try {
            Notification::route('mail', $recipient)
                ->notify(new OperationsHealthAlert($severity, $title, $context));

            return true;
        } catch (Throwable $exception) {
            try {
                Cache::forget($cacheKey);
            } catch (Throwable) {
                // El siguiente ciclo volverá a intentarlo cuando expire la clave.
            }

            Log::error('No se pudo enviar la alerta operativa por correo.', [
                'incident_key' => $incidentKey,
                'alert_title' => $title,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
