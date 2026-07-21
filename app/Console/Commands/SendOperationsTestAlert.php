<?php

namespace App\Console\Commands;

use App\Services\OperationsAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SendOperationsTestAlert extends Command
{
    protected $signature = 'operations:test-alert';

    protected $description = 'Envía una alerta operativa de prueba al destinatario configurado';

    public function handle(OperationsAlertService $alerts): int
    {
        $sent = $alerts->notify(
            'manual-test-'.Str::uuid(),
            'info',
            'Prueba de alertas operativas de CyC Loyal',
            ['resultado' => 'El canal de correo está funcionando correctamente.'],
        );

        if (! $sent) {
            $this->components->error('No se pudo enviar. Revisa OPERATIONS_ALERT_EMAIL y el transporte MAIL_MAILER.');

            return self::FAILURE;
        }

        $this->components->info('Alerta de prueba enviada.');

        return self::SUCCESS;
    }
}
