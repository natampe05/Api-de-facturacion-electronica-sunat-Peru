<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Console\Commands\CreateDirectoryStructure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('sunat-operations', function (Request $request) {
            $identity = $request->attributes->get('sunat_auth_user_id') ?: $request->ip();
            return Limit::perMinute(30)->by((string) $identity);
        });

        RateLimiter::for('sunat-downloads', function (Request $request) {
            $identity = $request->attributes->get('sunat_auth_user_id') ?: $request->ip();
            return Limit::perMinute(60)->by((string) $identity);
        });

        RateLimiter::for('sunat-admin', function (Request $request) {
            $identity = $request->attributes->get('sunat_auth_user_id') ?: $request->ip();
            return Limit::perMinute(10)->by((string) $identity);
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateDirectoryStructure::class,
            ]);
        }
    }
}
