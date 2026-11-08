<?php

namespace Tests\Feature;

use App\Contracts\BybitReadOnlyClient;
use App\Jobs\EvaluateSymbolJob;
use App\Jobs\ScanMarketsJob;
use App\Livewire\ScannerDashboard;
use App\Models\ScanRun;
use App\Models\TradingStrategy;
use App\Models\User;
use App\Services\Trading\FourHourCandleClock;
use App\Services\Trading\MarketUniverseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ScannerSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_loads_the_authenticated_users_saved_turnover_setting(): void
    {
        [$user, $strategy] = $this->activeStrategy('1000000.00000000');
        $this->actingAs($user);

        Livewire::test(ScannerDashboard::class)
            ->assertSet('minimumTurnover', '1000000.00000000')
            ->assertSet('rsiMaximum', '65.00')
            ->assertSee('Current saved value: 1,000,000.00000000 USDT')
            ->assertSee('Minimum 24H Turnover (USDT)')
            ->assertSee('RSI (14) Maximum · 4H');
    }

    public function test_owner_can_save_a_new_positive_turnover_setting_without_changing_history(): void
    {
        [$user, $strategy] = $this->activeStrategy('1000000.00000000');
        $run = $strategy->scanRuns()->create([
            'status' => 'completed',
            'started_at' => now()->subHour(),
            'finished_at' => now(),
            'minimum_turnover_snapshot' => '1000000.00000000',
        ]);
        $pair = $strategy->scannedPairs()->create([
            'symbol' => 'ALTUSDT',
            'base_coin' => 'ALT',
            'quote_coin' => 'USDT',
            'contract_type' => 'LinearPerpetual',
            'turnover_24h_usdt' => '1500000.00000000',
            'is_active' => true,
            'last_scanned_at' => now(),
        ]);
        $signal = $pair->signals()->create([
            'timeframe' => '4H',
            'candle_at' => now()->startOfDay(),
            'is_qualified' => true,
            'evaluation_reason' => 'all_conditions_met',
            'evaluated_at' => now(),
        ]);
        $this->actingAs($user);

        Livewire::test(ScannerDashboard::class)
            ->set('minimumTurnover', '2500000')
            ->call('saveSettings')
            ->assertHasNoErrors()
            ->assertSee('Scanner settings saved');

        $this->assertDatabaseHas('trading_strategies', [
            'id' => $strategy->id,
            'minimum_turnover_24h_usdt' => '2500000.00000000',
        ]);
        $this->assertSame('1000000.00000000', $run->fresh()->minimum_turnover_snapshot);
        $this->assertSame('all_conditions_met', $signal->fresh()->evaluation_reason);
        $this->assertTrue($signal->fresh()->is_qualified);
    }

    public function test_turnover_setting_rejects_non_numeric_and_zero_values(): void
    {
        [$user, $strategy] = $this->activeStrategy('1000000.00000000');
        $this->actingAs($user);

        foreach (['not-a-number', '0'] as $value) {
            Livewire::test(ScannerDashboard::class)
                ->set('minimumTurnover', $value)
                ->call('saveSettings')
                ->assertSee('Please correct the scanner settings')
                ->assertHasErrors(['minimumTurnover']);
        }

        $this->assertSame('1000000.00000000', $strategy->fresh()->minimum_turnover_24h_usdt);
    }

    public function test_rsi_maximum_setting_accepts_a_valid_value_and_rejects_values_outside_zero_to_100(): void
    {
        [$user, $strategy] = $this->activeStrategy('1000000.00000000');
        $this->actingAs($user);

        Livewire::test(ScannerDashboard::class)
            ->set('rsiMaximum', '62.5')
            ->call('saveSettings')
            ->assertHasNoErrors()
            ->assertSee('Scanner settings saved');

        $this->assertSame('62.50', $strategy->fresh()->rsi_maximum);

        foreach (['-0.01', '100.01', 'not-a-number'] as $value) {
            Livewire::test(ScannerDashboard::class)
                ->set('rsiMaximum', $value)
                ->call('saveSettings')
                ->assertSee('Please correct the scanner settings')
                ->assertHasErrors(['rsiMaximum']);
        }
    }

    public function test_user_cannot_modify_another_users_strategy(): void
    {
        [, $strategy] = $this->activeStrategy('1000000.00000000');
        $this->actingAs(User::factory()->create());

        Livewire::test(ScannerDashboard::class)
            ->set('minimumTurnover', '5000000')
            ->call('saveSettings')
            ->assertForbidden();

        $this->assertSame('1000000.00000000', $strategy->fresh()->minimum_turnover_24h_usdt);
    }

    public function test_manual_scan_uses_the_saved_turnover_setting(): void
    {
        [$user] = $this->activeStrategy('1000000.00000000');
        $this->actingAs($user);
        Bus::fake();

        Livewire::test(ScannerDashboard::class)
            ->set('minimumTurnover', '2000000')
            ->call('saveSettings')
            ->call('runScanNow')
            ->assertSee('Market scan queued');

        $job = Bus::dispatched(ScanMarketsJob::class)->first();
        $this->assertNotNull($job);
        Bus::fake([EvaluateSymbolJob::class]);
        $this->executeScanJob($job);

        $run = ScanRun::query()->firstOrFail();
        $this->assertSame('2000000.00000000', $run->minimum_turnover_snapshot);
        $this->assertSame('65.00', $run->rsi_maximum_snapshot);
        $this->assertDatabaseHas('scan_run_pair_results', [
            'evaluation_reason' => 'turnover_below_configured_minimum',
            'is_qualified' => false,
            'minimum_turnover_snapshot' => '2000000.00000000',
        ]);
    }

    public function test_scheduled_scan_uses_the_saved_turnover_setting(): void
    {
        [, $strategy] = $this->activeStrategy('1000000.00000000');
        Bus::fake();

        $this->assertSame(0, Artisan::call('bybit:scan'));
        $job = Bus::dispatched(ScanMarketsJob::class)->first();
        $this->assertNotNull($job);
        Bus::fake([EvaluateSymbolJob::class]);
        $this->executeScanJob($job, '500000');

        $run = ScanRun::query()->where('trading_strategy_id', $strategy->id)->firstOrFail();
        $this->assertSame('1000000.00000000', $run->minimum_turnover_snapshot);
        $this->assertSame('65.00', $run->rsi_maximum_snapshot);
        $this->assertDatabaseHas('scan_run_pair_results', [
            'evaluation_reason' => 'turnover_below_configured_minimum',
            'is_qualified' => false,
            'minimum_turnover_snapshot' => '1000000.00000000',
        ]);
    }

    public function test_running_scan_keeps_its_turnover_snapshot_after_settings_change(): void
    {
        [, $strategy] = $this->activeStrategy('1000000.00000000');
        $run = $strategy->scanRuns()->create([
            'status' => 'queued',
            'queued_at' => now(),
            'started_at' => now(),
        ]);
        $environment = (string) config('trading_scanner.environment', 'testnet');
        $client = Mockery::mock(BybitReadOnlyClient::class);
        $client->shouldReceive('getLinearInstruments')
            ->once()
            ->with(null, 1000, $environment)
            ->andReturnUsing(function () use ($strategy): array {
                TradingStrategy::query()->whereKey($strategy->id)->update([
                    'minimum_turnover_24h_usdt' => '2000000.00000000',
                    'rsi_maximum' => '55.00',
                ]);

                return ['list' => [$this->instrument()], 'nextPageCursor' => ''];
            });
        $client->shouldReceive('getTickers')
            ->once()
            ->with('linear', null, $environment)
            ->andReturn(['list' => [['symbol' => 'ALTUSDT', 'turnover24h' => '1500000']]]);
        Bus::fake([EvaluateSymbolJob::class]);

        (new ScanMarketsJob([$run->id]))->handle(new MarketUniverseService($client), new FourHourCandleClock);

        $this->assertSame('1000000.00000000', $run->fresh()->minimum_turnover_snapshot);
        $this->assertSame('65.00', $run->fresh()->rsi_maximum_snapshot);
        $this->assertSame('2000000.00000000', $strategy->fresh()->minimum_turnover_24h_usdt);
        Bus::assertDispatched(EvaluateSymbolJob::class);
    }

    private function executeScanJob(ScanMarketsJob $job, string $turnover = '1500000'): void
    {
        $environment = (string) config('trading_scanner.environment', 'testnet');
        $client = Mockery::mock(BybitReadOnlyClient::class);
        $client->shouldReceive('getLinearInstruments')
            ->once()
            ->with(null, 1000, $environment)
            ->andReturn(['list' => [$this->instrument()], 'nextPageCursor' => '']);
        $client->shouldReceive('getTickers')
            ->once()
            ->with('linear', null, $environment)
            ->andReturn(['list' => [['symbol' => 'ALTUSDT', 'turnover24h' => $turnover]]]);

        $job->handle(new MarketUniverseService($client), new FourHourCandleClock);
    }

    private function activeStrategy(string $minimumTurnover): array
    {
        $user = User::factory()->create();
        $strategy = $user->tradingStrategies()->create([
            'name' => 'Altcoin Bullish Scanner',
            'minimum_turnover_24h_usdt' => $minimumTurnover,
            'is_active' => true,
        ]);

        return [$user, $strategy];
    }

    private function instrument(): array
    {
        return [
            'symbol' => 'ALTUSDT',
            'baseCoin' => 'ALT',
            'quoteCoin' => 'USDT',
            'settleCoin' => 'USDT',
            'contractType' => 'LinearPerpetual',
            'status' => 'Trading',
        ];
    }
}
