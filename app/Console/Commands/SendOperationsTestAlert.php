<?php

namespace App\Console\Commands;

use App\Services\OperationsAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SendOperationsTestAlert extends Command
{
    protected $signature = 'operations:test-alert
        {--completed : Envía el aviso de trabajo pendiente terminado}';

    protected $description = 'Envía una alerta operativa de prueba al destinatario configurado';

    public function handle(OperationsAlertService $alerts): int
    {
        $completed = (bool) $this->option('completed');
        $title = $completed
            ? 'Ya terminé el trabajo pendiente'
            : 'Prueba de alertas operativas de CyC Loyal';
        $message = $completed
            ? 'Ya terminé el trabajo pendiente.'
            : 'El canal de correo está funcionando correctamente.';

        $sent = $alerts->notify(
            'manual-test-'.Str::uuid(),
            'info',
            $title,
            ['mensaje' => $message],
        );

        if (! $sent) {
            $this->components->error('No se pudo enviar. Revisa OPERATIONS_ALERT_EMAIL y el transporte MAIL_MAILER.');

            return self::FAILURE;
        }

        $this->components->info($completed
            ? 'Aviso de trabajo terminado enviado.'
            : 'Alerta de prueba enviada.');

        return self::SUCCESS;
    }
}
