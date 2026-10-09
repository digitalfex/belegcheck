<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Ratenbegrenzung je Mandant (nicht je IP – ERP-Systeme senden oft über einen Ausgang)
        RateLimiter::for('belegcheck-api', fn (Request $request) => Limit::perMinute((int) config('belegcheck.api.anfragen_je_minute'))
            ->by('mandant:'.($request->attributes->get('mandant')?->id ?? $request->ip())));
    }
}
