<?php

use App\Http\Controllers\Api\FacturacionController;

function determineSunatState(array $result, string $message): string
{
    $reflection = new ReflectionClass(FacturacionController::class);
    $controller = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('determineSunatEstado');

    return $method->invoke($controller, $result, $message);
}

it('keeps transport errors retryable', function () {
    $state = determineSunatState([
        'success' => false,
        'cdr_response' => null,
    ], 'Timeout al conectar con SUNAT');

    expect($state)->toBe('error');
});

it('does not retry explicit SUNAT rejections', function () {
    $state = determineSunatState([
        'success' => false,
        'cdr_response' => null,
    ], '[2800] Documento rechazado por SUNAT');

    expect($state)->toBe('rechazado');
});

it('treats previously registered documents as terminal acceptance', function () {
    $state = determineSunatState([
        'success' => false,
        'cdr_response' => null,
    ], '[1033] El comprobante fue registrado previamente');

    expect($state)->toBe('aceptado_observaciones');
});
