<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\AuthenticateSupabase;
use App\Http\Middleware\EnsureSunatSuperAdmin;
use App\Http\Middleware\EnsureSunatTenant;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'supabase.auth' => AuthenticateSupabase::class,
            'sunat.tenant' => EnsureSunatTenant::class,
            'sunat.superadmin' => EnsureSunatSuperAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
