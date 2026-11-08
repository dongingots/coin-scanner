<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\ScanMarketsJob;
use App\Models\User;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('bybit:scan', function () {
    ScanMarketsJob::dispatch();
    $this->info('Bybit market scan queued.');
})->purpose('Queue a read-only Bybit USDT perpetual market scan');

Artisan::command('bybit:strategy:configure {user_id} {--name=Altcoin Bullish Scanner} {--min-turnover=} {--volume-sma=} {--excluded=} {--include-btc} {--include-eth} {--include-stablecoins} {--activate}', function () {
    $user = User::query()->findOrFail((int) $this->argument('user_id'));
    $strategy = $user->tradingStrategies()->firstOrNew(['name' => $this->option('name')]);
    $minimumTurnover = $this->option('min-turnover');

    if ($minimumTurnover !== null && $minimumTurnover !== '') {
        if (strtolower($minimumTurnover) === 'none') {
            $strategy->minimum_turnover_24h_usdt = null;
        } elseif (! is_numeric($minimumTurnover) || ! is_finite((float) $minimumTurnover) || (float) $minimumTurnover < 0) {
            $this->error('Minimum turnover must be a non-negative USDT amount or "none".');

            return 1;
        } else {
            $strategy->minimum_turnover_24h_usdt = $minimumTurnover;
        }
    }

    $volumeSmaPeriod = $this->option('volume-sma');

    if ($volumeSmaPeriod !== null && $volumeSmaPeriod !== '') {
        if (filter_var($volumeSmaPeriod, FILTER_VALIDATE_INT) === false || (int) $volumeSmaPeriod < 1 || (int) $volumeSmaPeriod > 999) {
            $this->error('Volume SMA period must be an integer between 1 and 999.');

            return 1;
        }

        $strategy->volume_sma_period = (int) $volumeSmaPeriod;
    }

    if ($this->option('excluded') !== null) {
        $symbols = array_filter(array_map(
            static fn (string $symbol): string => strtoupper(trim($symbol)),
            explode(',', (string) $this->option('excluded')),
        ));
        $strategy->excluded_symbols = array_values(array_unique($symbols));
    }

    if ($this->option('include-btc')) {
        $strategy->exclude_btc = false;
    }

    if ($this->option('include-eth')) {
        $strategy->exclude_eth = false;
    }

    if ($this->option('include-stablecoins')) {
        $strategy->exclude_stablecoins = false;
    }

    if ($this->option('activate')) {
        $strategy->is_active = true;
    }

    $strategy->save();
    $this->info('Strategy settings saved. Minimum 24H turnover: '.($strategy->minimum_turnover_24h_usdt ?? 'not set').'; Volume SMA: '.$strategy->volume_sma_period.'.');

    return 0;
})->purpose('Create or update scanner strategy exclusions and liquidity settings');

Schedule::command('bybit:scan')
    ->cron('5 */4 * * *')
    ->timezone('UTC')
    ->withoutOverlapping(360)
    ->onOneServer();
