<?php

namespace App\Providers;

use App\Contracts\BybitReadOnlyClient;
use App\Services\Bybit\BybitV5Client;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(BybitReadOnlyClient::class, BybitV5Client::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('bybit-market-data', fn () => Limit::perSecond(
            max(1, (int) config('trading_scanner.requests_per_second', 4)),
        )->by('bybit-v5-read-only'));
    }
}
