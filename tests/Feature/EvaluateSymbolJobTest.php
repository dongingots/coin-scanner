<?php

namespace Tests\Feature;

use App\Contracts\BybitReadOnlyClient;
use App\Jobs\EvaluateSymbolJob;
use App\Models\TradingSignal;
use App\Models\User;
use App\Services\Trading\CandleDataNormalizer;
use App\Services\Trading\IndicatorCalculator;
use App\Services\Trading\TradingSignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class EvaluateSymbolJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluation_job_ignores_a_scan_run_that_is_already_terminal(): void
    {
        $strategy = User::factory()->create()->tradingStrategies()->create([
            'name' => 'Test strategy',
            'is_active' => true,
        ]);
        $pair = $strategy->scannedPairs()->create([
            'symbol' => 'ALTUSDT',
            'base_coin' => 'ALT',
            'quote_coin' => 'USDT',
            'contract_type' => 'LinearPerpetual',
            'turnover_24h_usdt' => '1000.00000000',
            'is_active' => true,
            'last_scanned_at' => now(),
        ]);
        $run = $strategy->scanRuns()->create([
            'status' => 'failed',
            'pair_count' => 1,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        $bybit = Mockery::mock(BybitReadOnlyClient::class);
        $bybit->shouldNotReceive('getKlines');

        (new EvaluateSymbolJob($pair->id, $run->id, now()->getTimestampMs()))->handle(
            $bybit,
            new CandleDataNormalizer,
            new TradingSignalService(new IndicatorCalculator),
        );

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(0, $run->fresh()->evaluated_count);
        $this->assertSame(0, TradingSignal::query()->count());
    }

    public function test_mocked_bybit_closed_candles_are_evaluated_and_persisted(): void
    {
        $strategy = User::factory()->create()->tradingStrategies()->create([
            'name' => 'Test strategy',
            'is_active' => true,
        ]);
        $pair = $strategy->scannedPairs()->create([
            'symbol' => 'ALTUSDT',
            'base_coin' => 'ALT',
            'quote_coin' => 'USDT',
            'contract_type' => 'LinearPerpetual',
            'turnover_24h_usdt' => '1000.00000000',
            'is_active' => true,
            'last_scanned_at' => now(),
        ]);
        $run = $strategy->scanRuns()->create([
            'status' => 'running',
            'pair_count' => 1,
            'started_at' => now(),
        ]);

        $intervalMs = 14_400_000;
        $currentOpenMs = intdiv(now('UTC')->getTimestampMs(), $intervalMs) * $intervalMs;
        $targetOpenMs = $currentOpenMs - $intervalMs;
        Carbon::setTestNow(Carbon::createFromTimestampMs($currentOpenMs + 300_000, 'UTC'));

        $rows = [];
        for ($index = 0; $index < 400; $index++) {
            $close = 100 + $index;
            $open = $close - 0.5;
            $rows[] = [
                (string) ($targetOpenMs - ((399 - $index) * $intervalMs)),
                (string) $open,
                (string) ($close + 1),
                (string) ($open - 1),
                (string) $close,
                $index === 399 ? '100' : '10',
                '500',
            ];
        }
        $rows[] = [(string) $currentOpenMs, '500', '501', '499', '500', '20', '100'];
        $rows = array_reverse($rows);

        $bybit = Mockery::mock(BybitReadOnlyClient::class);
        $bybit->shouldReceive('getKlines')
            ->once()
            ->withArgs(fn (...$arguments): bool => $arguments[0] === 'ALTUSDT'
                && $arguments[1] === '4H'
                && $arguments[2] === 'linear'
                && $arguments[5] === 401)
            ->andReturn(['list' => $rows]);

        $job = new EvaluateSymbolJob($pair->id, $run->id, $targetOpenMs);
        $job->handle($bybit, new CandleDataNormalizer, new TradingSignalService(new IndicatorCalculator));

        $signal = TradingSignal::query()->firstOrFail();
        $this->assertSame($targetOpenMs, $signal->candle_at->getTimestampMs());
        $this->assertTrue($signal->is_qualified);
        $this->assertTrue($signal->ema_bullish);
        $this->assertTrue($signal->ha_bullish);
        $this->assertTrue($signal->volume_confirmed);
        $this->assertSame('all_conditions_met', $signal->evaluation_reason);
        $this->assertSame('valid', $signal->rsi_data_status);
        $this->assertSame('100.0000', $signal->rsi_14);
        $this->assertDatabaseHas('scan_run_pair_results', [
            'scan_run_id' => $run->id,
            'rsi_status' => 'rejected',
            'evaluation_reason' => 'rsi_above_maximum',
            'is_qualified' => false,
        ]);
        $this->assertSame('completed', $run->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_existing_signal_prevents_a_duplicate_candle_request_and_evaluation(): void
    {
        $strategy = User::factory()->create()->tradingStrategies()->create([
            'name' => 'Test strategy',
            'is_active' => true,
        ]);
        $pair = $strategy->scannedPairs()->create([
            'symbol' => 'ALTUSDT',
            'base_coin' => 'ALT',
            'quote_coin' => 'USDT',
            'contract_type' => 'LinearPerpetual',
            'turnover_24h_usdt' => '1000.00000000',
            'is_active' => true,
            'last_scanned_at' => now(),
        ]);
        $run = $strategy->scanRuns()->create([
            'status' => 'running',
            'pair_count' => 1,
            'started_at' => now(),
        ]);
        $candleAt = now('UTC')->startOfDay();
        $pair->signals()->create([
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
            'evaluated_at' => now(),
        ]);

        $bybit = Mockery::mock(BybitReadOnlyClient::class);
        $bybit->shouldNotReceive('getKlines');

        $job = new EvaluateSymbolJob($pair->id, $run->id, $candleAt->getTimestampMs());
        $job->handle($bybit, new CandleDataNormalizer, new TradingSignalService(new IndicatorCalculator));

        $this->assertSame(1, TradingSignal::query()->count());
        $this->assertSame(1, $run->fresh()->evaluated_count);
        $this->assertSame(1, $run->fresh()->qualified_count);
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_rsi_uses_only_closed_regular_four_hour_candles(): void
    {
        $strategy = User::factory()->create()->tradingStrategies()->create([
            'name' => 'Test strategy',
            'is_active' => true,
        ]);
        $pair = $strategy->scannedPairs()->create([
            'symbol' => 'ALTUSDT',
            'base_coin' => 'ALT',
            'quote_coin' => 'USDT',
            'contract_type' => 'LinearPerpetual',
            'turnover_24h_usdt' => '1000000.00000000',
            'is_active' => true,
            'last_scanned_at' => now(),
        ]);
        $intervalMs = 14_400_000;
        $currentOpenMs = intdiv(now('UTC')->getTimestampMs(), $intervalMs) * $intervalMs;
        $targetOpenMs = $currentOpenMs - $intervalMs;
        Carbon::setTestNow(Carbon::createFromTimestampMs($currentOpenMs + 300_000, 'UTC'));
        $run = $strategy->scanRuns()->create([
            'status' => 'running',
            'pair_count' => 1,
            'started_at' => now(),
            'candle_at' => Carbon::createFromTimestampMs($targetOpenMs, 'UTC'),
            'rsi_maximum_snapshot' => '65.00',
        ]);
        $result = $run->pairResults()->create([
            'scanned_pair_id' => $pair->id,
            'turnover_24h_snapshot' => '1000000.00000000',
            'status' => 'pending',
        ]);

        $closes = [];
        for ($index = 0; $index <= 385; $index++) {
            $closes[] = 100 + $index;
        }
        for ($index = 0; $index < 13; $index++) {
            $closes[] = end($closes) + 0.01;
        }
        $closes[] = end($closes) - 0.07;

        $rows = [];
        foreach ($closes as $index => $close) {
            $open = $close - 0.5;
            $rows[] = [
                (string) ($targetOpenMs - ((399 - $index) * $intervalMs)),
                (string) $open,
                (string) ($close + 1),
                (string) ($close - 1.5),
                (string) $close,
                $index === 399 ? '100' : '10',
                '1000000',
            ];
        }
        $rows[] = [(string) $currentOpenMs, '499', '1001', '498', '1000', '100000', '999999999'];
        $rows = array_reverse($rows);

        $bybit = Mockery::mock(BybitReadOnlyClient::class);
        $bybit->shouldReceive('getKlines')
            ->once()
            ->withArgs(fn (...$arguments): bool => $arguments[0] === 'ALTUSDT'
                && $arguments[1] === '4H'
                && $arguments[2] === 'linear'
                && $arguments[5] === 401)
            ->andReturn(['list' => $rows]);

        (new EvaluateSymbolJob($pair->id, $run->id, $targetOpenMs, $result->id))->handle(
            $bybit,
            new CandleDataNormalizer,
            new TradingSignalService(new IndicatorCalculator),
        );

        $signal = TradingSignal::query()->firstOrFail();
        $result->refresh();
        $expectedClosedRsi = (new IndicatorCalculator)->rsi($closes, 14);
        $includingFormingCandleRsi = (new IndicatorCalculator)->rsi(array_merge($closes, [1000.0]), 14);
        $this->assertEqualsWithDelta($expectedClosedRsi, (float) $signal->rsi_14, 0.0001);
        $this->assertNotEqualsWithDelta($includingFormingCandleRsi, (float) $signal->rsi_14, 0.0001);
        $this->assertSame('rejected', $result->rsi_status);
        $this->assertStringContainsString('rsi_above_maximum', $result->evaluation_reason);
        $this->assertSame('completed', $run->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_two_runs_apply_their_own_rsi_threshold_to_a_reused_indicator(): void
    {
        $strategy = User::factory()->create()->tradingStrategies()->create([
            'name' => 'Test strategy',
            'is_active' => true,
            'rsi_maximum' => '65.00',
        ]);
        $pair = $strategy->scannedPairs()->create([
            'symbol' => 'ALTUSDT',
            'base_coin' => 'ALT',
            'quote_coin' => 'USDT',
            'contract_type' => 'LinearPerpetual',
            'turnover_24h_usdt' => '1000000.00000000',
            'is_active' => true,
            'last_scanned_at' => now(),
        ]);
        $candleAt = now('UTC')->startOfDay();
        $pair->signals()->create([
            'timeframe' => '4H',
            'candle_at' => $candleAt,
            'ema_100' => '200',
            'ema_200' => '180',
            'ha_open' => '199',
            'ha_close' => '201',
            'volume' => '20',
            'volume_sma' => '15',
            'rsi_14' => '65.0000',
            'rsi_data_status' => 'valid',
            'ema_bullish' => true,
            'ha_bullish' => true,
            'volume_confirmed' => true,
            'is_qualified' => true,
            'evaluation_reason' => 'all_conditions_met',
            'evaluated_at' => now(),
        ]);

        $runs = collect(['65.00', '64.99'])->map(function (string $threshold) use ($strategy, $pair) {
            $run = $strategy->scanRuns()->create([
                'status' => 'running',
                'pair_count' => 1,
                'started_at' => now(),
                'candle_at' => $pair->signals()->first()->candle_at,
                'rsi_maximum_snapshot' => $threshold,
            ]);
            $run->pairResults()->create([
                'scanned_pair_id' => $pair->id,
                'turnover_24h_snapshot' => '1000000.00000000',
                'status' => 'pending',
            ]);

            return $run;
        });

        $strategy->update(['rsi_maximum' => '50.00']);
        $bybit = Mockery::mock(BybitReadOnlyClient::class);
        $bybit->shouldNotReceive('getKlines');

        foreach ($runs as $run) {
            $result = $run->pairResults()->firstOrFail();
            (new EvaluateSymbolJob($pair->id, $run->id, $run->candle_at->getTimestampMs(), $result->id))->handle(
                $bybit,
                new CandleDataNormalizer,
                new TradingSignalService(new IndicatorCalculator),
            );
        }

        $this->assertSame('65.00', $runs[0]->fresh()->rsi_maximum_snapshot);
        $this->assertSame('64.99', $runs[1]->fresh()->rsi_maximum_snapshot);
        $this->assertSame('passed', $runs[0]->pairResults()->firstOrFail()->rsi_status);
        $this->assertTrue($runs[0]->pairResults()->firstOrFail()->is_qualified);
        $this->assertSame('rejected', $runs[1]->pairResults()->firstOrFail()->rsi_status);
        $this->assertSame('rsi_above_maximum', $runs[1]->pairResults()->firstOrFail()->evaluation_reason);
        $this->assertFalse($runs[1]->pairResults()->firstOrFail()->is_qualified);
    }
}
