<?php

use App\Jobs\ProcessSunatOrder;
use App\Services\PendingSunatOrderService;
use App\Services\SunatOrderSender;
use Illuminate\Support\Facades\Queue;

it('dispatches every claimed SUNAT order to the independent queue', function () {
    Queue::fake();
    config()->set('sunat_worker.enabled', true);
    config()->set('sunat_worker.queue', 'sunat');

    $pending = Mockery::mock(PendingSunatOrderService::class);
    $pending->shouldReceive('claim')->once()->with(null)->andReturn(['order-1', 'order-2']);
    app()->instance(PendingSunatOrderService::class, $pending);

    $this->artisan('sunat:dispatch-pending')
        ->expectsOutputToContain('2 comprobante(s)')
        ->assertSuccessful();

    Queue::assertPushed(ProcessSunatOrder::class, 2);
    Queue::assertPushed(ProcessSunatOrder::class, fn (ProcessSunatOrder $job) => $job->orderId === 'order-1' && $job->queue === 'sunat');
});

it('closes the lease when SUNAT returns a terminal result', function () {
    $sender = Mockery::mock(SunatOrderSender::class);
    $sender->shouldReceive('send')->once()->with('order-1')->andReturn([
        'terminal' => true,
        'accepted' => true,
        'state' => 'enviado',
        'message' => 'Aceptado',
        'status' => 200,
    ]);

    $pending = Mockery::mock(PendingSunatOrderService::class);
    $pending->shouldReceive('complete')->once()->with('order-1');
    $pending->shouldNotReceive('release');

    (new ProcessSunatOrder('order-1'))->handle($sender, $pending);
});

it('schedules a database backoff for transient SUNAT failures', function () {
    $sender = Mockery::mock(SunatOrderSender::class);
    $sender->shouldReceive('send')->once()->with('order-1')->andReturn([
        'terminal' => false,
        'accepted' => false,
        'state' => 'error',
        'message' => 'Timeout de red',
        'status' => 503,
    ]);

    $pending = Mockery::mock(PendingSunatOrderService::class);
    $pending->shouldReceive('release')->once()->with('order-1', 'Timeout de red');
    $pending->shouldNotReceive('complete');

    (new ProcessSunatOrder('order-1'))->handle($sender, $pending);
});

it('uses progressive retry delays capped at the final configured value', function () {
    config()->set('sunat_worker.backoff_seconds', [60, 180, 600]);
    $service = new PendingSunatOrderService;

    expect($service->delayForAttempt(1))->toBe(60)
        ->and($service->delayForAttempt(2))->toBe(180)
        ->and($service->delayForAttempt(8))->toBe(600);
});
