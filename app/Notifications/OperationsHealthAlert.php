<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OperationsHealthAlert extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $severity,
        public readonly string $title,
        public readonly array $context = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(sprintf('[%s] %s', strtoupper($this->severity), $this->title))
            ->greeting($this->title)
            ->line(sprintf(
                'Aplicación: %s | Entorno: %s | Fecha UTC: %s',
                config('app.name'),
                app()->environment(),
                now('UTC')->toIso8601String(),
            ));

        foreach ($this->context as $label => $value) {
            if (is_array($value)) {
                $value = $value === [] ? 'ninguno' : implode(', ', array_map('strval', $value));
            } elseif (is_bool($value)) {
                $value = $value ? 'sí' : 'no';
            } elseif ($value === null || $value === '') {
                $value = 'no disponible';
            }

            $message->line(sprintf('%s: %s', str_replace('_', ' ', ucfirst((string) $label)), (string) $value));
        }

        return $message->line('Revisa el servicio y sus registros operativos antes de intervenir datos de producción.');
    }
}
