<?php

namespace Tests\Feature;

use App\Contracts\BybitReadOnlyClient;
use App\Jobs\EvaluateSymbolJob;
use App\Models\ScanRun;
use App\Models\ScannedPair;
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
}
