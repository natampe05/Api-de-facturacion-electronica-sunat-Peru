<?php

use App\Services\PostgresConnectionUsage;
use Illuminate\Support\Facades\DB;

it('excludes PostgreSQL background workers and reserved slots from connection usage', function () {
    DB::shouldReceive('selectOne')
        ->once()
        ->with(Mockery::on(fn (string $sql) => str_contains($sql, "backend_type = 'client backend'")))
        ->andReturn((object) [
            'used_connections' => 32,
            'active_connections' => 1,
            'idle_connections' => 31,
            'idle_in_transaction_connections' => 0,
            'max_connections' => 60,
            'reserved_connections' => 3,
        ]);

    $usage = app(PostgresConnectionUsage::class)->measure();

    expect($usage)->toBe([
        'used' => 32,
        'active' => 1,
        'idle' => 31,
        'idle_in_transaction' => 0,
        'maximum' => 60,
        'reserved' => 3,
        'usable' => 57,
        'percent' => 56.14,
    ]);
});

it('keeps the percentage calculation safe when PostgreSQL reports invalid limits', function () {
    DB::shouldReceive('selectOne')->once()->andReturn((object) [
        'used_connections' => 1,
        'active_connections' => 1,
        'idle_connections' => 0,
        'idle_in_transaction_connections' => 0,
        'max_connections' => 0,
        'reserved_connections' => 3,
    ]);

    $usage = app(PostgresConnectionUsage::class)->measure();

    expect($usage['usable'])->toBe(1)
        ->and($usage['percent'])->toBe(100.0);
});
