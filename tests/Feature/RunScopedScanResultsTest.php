<?php

namespace Tests\Feature;

use App\Contracts\BybitReadOnlyClient;
use App\Jobs\EvaluateSymbolJob;
use App\Jobs\ScanMarketsJob;
use App\Livewire\ScannerDashboard;
use App\Models\ScanRun;
use App\Models\TradingSignal;
use App\Models\User;
use App\Services\Trading\CandleDataNormalizer;
use App\Services\Trading\FourHourCandleClock;
use App\Services\Trading\IndicatorCalculator;
use App\Services\Trading\MarketUniverseService;
use App\Services\Trading\TradingSignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class RunScopedScanResultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_scans_of_the_same_candle_keep_817_run_specific_results_and_reuse_valid_indicators(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-08 08:05:00 UTC'));
        $strategy = $this->strategy('500000.00000000');
        $candleOpenMs = app(FourHourCandleClock::class)->latestClosedCandleOpenMilliseconds();
        $candleAt = Carbon::createFromTimestampMs($candleOpenMs, 'UTC');
        $pair = $strategy->scannedPairs()->create([
            'symbol' => 'MIDUSDT',
            'base_coin' => 'MID',
            'quote_coin' => 'USDT',
            'contract_type' => 'LinearPerpetual',
            'turnover_24h_usdt' => '400000.00000000',
            'is_active' => true,
            'last_scanned_at' => now(),
        ]);
        $indicator = $pair->signals()->create([
            'timeframe' => '4H',
            'candle_at' => $candleAt,
            'ema_100' => '200.000000000000',
            'ema_200' => '180.000000000000',
            'ha_open' => '199.000000000000',
            'ha_close' => '201.000000000000',
            'volume' => '20.000000000000',
            'volume_sma' => '15.000000000000',
            'rsi_14' => '55.0000',
            'rsi_data_status' => 'valid',
            'ema_bullish' => true,
            'ha_bullish' => true,
            'volume_confirmed' => true,
            'is_qualified' => true,
            'evaluation_reason' => 'all_conditions_met',
            'evaluated_at' => now()->subMinute(),
        ]);

        [$instruments, $tickers] = $this->marketSnapshot();
        $environment = (string) config('trading_scanner.environment', 'testnet');
        $client = Mockery::mock(BybitReadOnlyClient::class);
        $client->shouldReceive('getLinearInstruments')->twice()
            ->with(null, 1000, $environment)
            ->andReturn(['list' => $instruments, 'nextPageCursor' => '']);
        $client->shouldReceive('getTickers')->twice()
            ->with('linear', null, $environment)
            ->andReturn(['list' => $tickers]);
        Bus::fake([EvaluateSymbolJob::class]);
        $universe = new MarketUniverseService($client);
        $clock = new FourHourCandleClock;

        (new ScanMarketsJob)->handle($universe, $clock);
        $firstRun = ScanRun::query()->orderBy('id')->firstOrFail();
        $firstMidResult = $firstRun->pairResults()->where('scanned_pair_id', $pair->id)->firstOrFail();

        $this->assertSame(817, $firstRun->pair_count);
        $this->assertSame(817, $firstRun->pairResults()->count());
        $this->assertSame('500000.00000000', $firstMidResult->minimum_turnover_snapshot);
        $this->assertSame('65.00', $firstRun->rsi_maximum_snapshot);
        $this->assertSame('400000.00000000', $firstMidResult->turnover_24h_snapshot);
        $this->assertSame('evaluated', $firstMidResult->status);
        $this->assertFalse($firstMidResult->is_qualified);
        $this->assertSame('turnover_below_configured_minimum', $firstMidResult->evaluation_reason);

        $strategy->update(['minimum_turnover_24h_usdt' => '250000.00000000']);
        (new ScanMarketsJob)->handle($universe, $clock);
        $secondRun = ScanRun::query()->orderByDesc('id')->firstOrFail();
        $secondMidResult = $secondRun->pairResults()->where('scanned_pair_id', $pair->id)->firstOrFail();

        $this->assertSame($candleAt->toDateTimeString(), $secondRun->candle_at->toDateTimeString());
        $this->assertSame(817, $secondRun->pair_count);
        $this->assertSame(817, $secondRun->pairResults()->count());
        $this->assertSame('250000.00000000', $secondMidResult->minimum_turnover_snapshot);
        $this->assertSame('65.00', $secondRun->rsi_maximum_snapshot);
        $this->assertSame('400000.00000000', $secondMidResult->turnover_24h_snapshot);
        $this->assertSame('pending', $secondMidResult->status);
        Bus::assertDispatched(EvaluateSymbolJob::class, fn (EvaluateSymbolJob $job): bool => $job->scannedPairId === $pair->id
            && $job->scanRunId === $secondRun->id
            && $job->scanRunPairResultId === $secondMidResult->id);

        $bybit = Mockery::mock(BybitReadOnlyClient::class);
        $bybit->shouldNotReceive('getKlines');
        $evaluationJob = new EvaluateSymbolJob($pair->id, $secondRun->id, $candleOpenMs, $secondMidResult->id);
        $evaluationJob->handle(
            $bybit,
            new CandleDataNormalizer,
            new TradingSignalService(new IndicatorCalculator),
        );

        $secondMidResult->refresh();
        $this->assertSame('evaluated', $secondMidResult->status);
        $this->assertTrue($secondMidResult->is_qualified);
        $this->assertSame($indicator->id, $secondMidResult->indicator_signal_id);
        $this->assertSame('all_conditions_met', $secondMidResult->evaluation_reason);
        $this->assertSame('passed', $secondMidResult->rsi_status);
        $this->assertSame('turnover_below_configured_minimum', $firstMidResult->fresh()->evaluation_reason);
        $this->assertSame(1, $secondRun->fresh()->qualified_count);

        $evaluationJob->handle(
            $bybit,
            new CandleDataNormalizer,
            new TradingSignalService(new IndicatorCalculator),
        );
        $this->assertSame(1, $secondRun->fresh()->evaluated_count);
        $this->assertSame(1, $secondRun->fresh()->qualified_count);

        Carbon::setTestNow();
    }

    public function test_dashboard_uses_only_the_latest_scan_runs_results_and_counters(): void
    {
        $strategy = $this->strategy('250000.00000000');
        $olderRun = $strategy->scanRuns()->create([
            'status' => 'completed',
            'pair_count' => 1,
            'evaluated_count' => 1,
            'qualified_count' => 1,
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHours(1),
        ]);
        $oldPair = $this->pair($strategy, 'OLDUSDT', '900000.00000000');
        $oldSignal = $this->indicatorSignal($oldPair, true);
        $olderRun->pairResults()->create([
            'scanned_pair_id' => $oldPair->id,
            'indicator_signal_id' => $oldSignal->id,
            'turnover_24h_snapshot' => '900000.00000000',
            'minimum_turnover_snapshot' => '250000.00000000',
            'status' => 'evaluated',
            'is_qualified' => true,
            'evaluation_reason' => 'all_conditions_met',
            'evaluated_at' => now()->subHours(2),
        ]);

        $latestRun = $strategy->scanRuns()->create([
            'status' => 'completed',
            'pair_count' => 1,
            'evaluated_count' => 1,
            'not_qualified_count' => 1,
            'started_at' => now()->subMinutes(2),
            'finished_at' => now(),
            'minimum_turnover_snapshot' => '500000.00000000',
        ]);
        $latestPair = $this->pair($strategy, 'NEWUSDT', '400000.00000000');
        $latestRun->pairResults()->create([
            'scanned_pair_id' => $latestPair->id,
            'turnover_24h_snapshot' => '400000.00000000',
            'minimum_turnover_snapshot' => '500000.00000000',
            'status' => 'evaluated',
            'is_qualified' => false,
            'evaluation_reason' => 'turnover_below_configured_minimum',
            'evaluated_at' => now(),
        ]);

        Livewire::test(ScannerDashboard::class)
            ->assertSee('#'.$latestRun->id.' · Completed')
            ->assertSee('Altcoins scanned')
            ->assertSee('NEWUSDT')
            ->assertSee('500,000.00000000 USDT')
            ->assertDontSee('OLDUSDT')
            ->assertDontSee('900,000.00000000 USDT');
    }

    public function test_duplicate_scan_job_execution_does_not_create_duplicate_pair_results(): void
    {
        $strategy = $this->strategy('500000.00000000');
        $run = $strategy->scanRuns()->create([
            'status' => 'queued',
            'queued_at' => now(),
            'started_at' => now(),
            'minimum_turnover_snapshot' => '500000.00000000',
        ]);
        $client = Mockery::mock(BybitReadOnlyClient::class);
        $environment = (string) config('trading_scanner.environment', 'testnet');
        $client->shouldReceive('getLinearInstruments')
            ->once()
            ->with(null, 1000, $environment)
            ->andReturn(['list' => [$this->instrument('LOWUSDT', 'LOW')], 'nextPageCursor' => '']);
        $client->shouldReceive('getTickers')
            ->once()
            ->with('linear', null, $environment)
            ->andReturn(['list' => [['symbol' => 'LOWUSDT', 'turnover24h' => '100000.00']]]);
        $universe = new MarketUniverseService($client);
        $job = new ScanMarketsJob([$run->id]);

        $job->handle($universe, new FourHourCandleClock);
        $job->handle($universe, new FourHourCandleClock);

        $this->assertSame(1, $run->pairResults()->count());
        $this->assertSame(1, $run->fresh()->pair_count);
        $this->assertSame(1, $run->fresh()->not_qualified_count);
        $this->assertSame('completed', $run->fresh()->status);
    }

    private function strategy(string $minimumTurnover)
    {
        return User::factory()->create()->tradingStrategies()->create([
            'name' => 'Scanner',
            'is_active' => true,
            'minimum_turnover_24h_usdt' => $minimumTurnover,
            'volume_sma_period' => 20,
            'exclude_btc' => true,
            'exclude_eth' => true,
            'exclude_stablecoins' => true,
            'excluded_symbols' => [],
        ]);
    }

    private function pair($strategy, string $symbol, string $turnover)
    {
        return $strategy->scannedPairs()->create([
            'symbol' => $symbol,
            'base_coin' => str_replace('USDT', '', $symbol),
            'quote_coin' => 'USDT',
            'contract_type' => 'LinearPerpetual',
            'turnover_24h_usdt' => $turnover,
            'is_active' => true,
            'last_scanned_at' => now(),
        ]);
    }

    private function indicatorSignal($pair, bool $qualified): TradingSignal
    {
        return $pair->signals()->create([
            'timeframe' => '4H',
            'candle_at' => now()->startOfDay(),
            'ema_100' => '200.000000000000',
            'ema_200' => '180.000000000000',
            'ha_open' => '199.000000000000',
            'ha_close' => $qualified ? '201.000000000000' : '198.000000000000',
            'volume' => $qualified ? '20.000000000000' : '10.000000000000',
            'volume_sma' => '15.000000000000',
            'rsi_14' => '55.0000',
            'rsi_data_status' => 'valid',
            'ema_bullish' => true,
            'ha_bullish' => $qualified,
            'volume_confirmed' => $qualified,
            'is_qualified' => $qualified,
            'evaluation_reason' => $qualified ? 'all_conditions_met' : 'latest_heikin_ashi_not_green;volume_not_above_sma',
            'evaluated_at' => now(),
        ]);
    }

    private function marketSnapshot(): array
    {
        $instruments = [];
        $tickers = [];

        for ($index = 0; $index < 817; $index++) {
            $base = $index === 0 ? 'MID' : 'ALT'.str_pad((string) $index, 3, '0', STR_PAD_LEFT);
            $symbol = $base.'USDT';
            $instruments[] = [
                'symbol' => $symbol,
                'baseCoin' => $base,
                'quoteCoin' => 'USDT',
                'settleCoin' => 'USDT',
                'contractType' => 'LinearPerpetual',
                'status' => 'Trading',
            ];
            $tickers[] = [
                'symbol' => $symbol,
                'turnover24h' => $index === 0 ? '400000.00' : '600000.00',
            ];
        }

        return [$instruments, $tickers];
    }

    private function instrument(string $symbol, string $base): array
    {
        return [
            'symbol' => $symbol,
            'baseCoin' => $base,
            'quoteCoin' => 'USDT',
            'settleCoin' => 'USDT',
            'contractType' => 'LinearPerpetual',
            'status' => 'Trading',
        ];
    }
}
