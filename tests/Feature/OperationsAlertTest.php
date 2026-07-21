<?php

use App\Notifications\OperationsHealthAlert;
use App\Services\OperationsAlertService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config()->set('cache.default', 'array');
    config()->set('mail.default', 'smtp');
    config()->set('operations.alert_email', 'alerts@example.test');
    config()->set('operations.alert_cooldown_minutes', 60);
    Cache::flush();
    Notification::fake();
});

it('sends an operational alert to the configured on-demand recipient', function () {
    $sent = app(OperationsAlertService::class)->notify(
        'sunat-test',
        'warning',
        'Prueba de alerta',
        ['queue_depth' => 120],
    );

    expect($sent)->toBeTrue();
    Notification::assertSentOnDemand(OperationsHealthAlert::class, function ($notification, $channels, $notifiable) {
        return $notification->title === 'Prueba de alerta'
            && in_array('mail', $channels, true)
            && $notifiable->routes['mail'] === 'alerts@example.test';
    });
});

it('suppresses repeated alerts during the configured cooldown', function () {
    $alerts = app(OperationsAlertService::class);

    expect($alerts->notify('same-incident', 'warning', 'Primera alerta'))->toBeTrue()
        ->and($alerts->notify('same-incident', 'warning', 'Alerta repetida'))->toBeFalse();

    Notification::assertSentOnDemandTimes(OperationsHealthAlert::class, 1);
});

it('does not mark an alert as delivered when the mailer only writes logs', function () {
    config()->set('mail.default', 'log');

    expect(app(OperationsAlertService::class)->notify('log-only', 'warning', 'Sin transporte'))->toBeFalse();
    Notification::assertNothingSent();
});

it('provides an artisan command that verifies the configured alert channel', function () {
    $this->artisan('operations:test-alert')
        ->expectsOutputToContain('Alerta de prueba enviada')
        ->assertSuccessful();

    Notification::assertSentOnDemandTimes(OperationsHealthAlert::class, 1);
});

it('sends the completed-work message through the safe artisan option', function () {
    $this->artisan('operations:test-alert --completed')
        ->expectsOutputToContain('Aviso de trabajo terminado enviado')
        ->assertSuccessful();

    Notification::assertSentOnDemand(
        OperationsHealthAlert::class,
        fn ($notification) => $notification->title === 'Ya terminé el trabajo pendiente',
    );
});
