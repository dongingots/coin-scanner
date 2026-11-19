<?php

namespace Tests\Unit;

use App\Models\TradingStrategy;
use App\Services\Trading\IndicatorCalculator;
use App\Services\Trading\TradingSignalService;
use PHPUnit\Framework\TestCase;

class IndicatorCalculatorTest extends TestCase
{
    public function test_ema_uses_an_sma_seed_then_the_standard_recursive_formula(): void
    {
        $ema = (new IndicatorCalculator)->ema([1, 2, 3, 4], 3);

        $this->assertEqualsWithDelta(3.0, $ema, 0.000001);
    }

    public function test_heikin_ashi_uses_the_previous_heikin_ashi_values(): void
    {
        $result = (new IndicatorCalculator)->heikinAshi([
            ['open' => 10.0, 'high' => 14.0, 'low' => 8.0, 'close' => 12.0],
            ['open' => 12.0, 'high' => 16.0, 'low' => 11.0, 'close' => 15.0],
        ]);

        $this->assertEqualsWithDelta(11.0, $result[0]['open'], 0.000001);
        $this->assertEqualsWithDelta(11.0, $result[0]['close'], 0.000001);
        $this->assertEqualsWithDelta(11.0, $result[1]['open'], 0.000001);
        $this->assertEqualsWithDelta(13.5, $result[1]['close'], 0.000001);
        $this->assertEqualsWithDelta(16.0, $result[1]['high'], 0.000001);
        $this->assertEqualsWithDelta(11.0, $result[1]['low'], 0.000001);
    }

    public function test_volume_sma_includes_the_latest_closed_candle(): void
    {
        $sma = (new IndicatorCalculator)->volumeSma([1, 2, 3, 4], 3);

        $this->assertEqualsWithDelta(3.0, $sma, 0.000001);
    }

    public function test_rsi_uses_wilder_smoothing_with_a_simple_average_seed(): void
    {
        $closes = $this->closesFromChanges(array_merge(array_fill(0, 13, 1.0), [-1.0, -1.0]));

        $rsi = (new IndicatorCalculator)->rsi($closes, 14);

        $this->assertEqualsWithDelta(86.2244897959, $rsi, 0.0000001);
    }

    public function test_rsi_returns_null_for_insufficient_or_invalid_close_data(): void
    {
        $calculator = new IndicatorCalculator;

        $this->assertNull($calculator->rsi(array_fill(0, 14, 100.0), 14));
        $this->assertNull($calculator->rsi(array_merge(array_fill(0, 14, 100.0), ['not-a-price']), 14));
        $this->assertNull($calculator->rsi(array_merge(array_fill(0, 14, 100.0), [NAN]), 14));
    }

    public function test_rsi_qualification_accepts_values_below_and_equal_to_65_and_rejects_above(): void
    {
        $calculator = new IndicatorCalculator;
        $signalService = new TradingSignalService($calculator);
        $baseline = ['ema_bullish' => true, 'ha_bullish' => true, 'volume_confirmed' => true, 'rsi_data_status' => 'valid'];
        $below = $calculator->rsi($this->closesFromChanges(array_merge(array_fill(0, 13, 1.0), [-10.0])), 14);
        $equal = $calculator->rsi($this->closesFromChanges(array_merge(array_fill(0, 13, 1.0), [-7.0])), 14);
        $above = $calculator->rsi($this->closesFromChanges(array_merge(array_fill(0, 13, 1.0), [-6.0])), 14);

        $this->assertEqualsWithDelta(56.52173913, $below, 0.000001);
        $this->assertEqualsWithDelta(65.0, $equal, 0.000001);
        $this->assertGreaterThan(65, $above);
        $this->assertTrue($signalService->qualify($baseline + ['rsi_14' => $below], 65)['is_qualified']);
        $this->assertSame('passed', $signalService->qualify($baseline + ['rsi_14' => $equal], 65)['rsi_status']);
        $this->assertSame('rsi_above_maximum', $signalService->qualify($baseline + ['rsi_14' => $above], 65)['evaluation_reason']);
        $this->assertFalse($signalService->qualify($baseline + ['rsi_14' => $above], 65)['is_qualified']);
    }

    public function test_ema_trend_filter_controls_run_qualification(): void
    {
        $service = new TradingSignalService(new IndicatorCalculator);
        $indicators = [
            'ema_bullish' => true,
            'ha_bullish' => true,
            'volume_confirmed' => true,
            'rsi_data_status' => 'valid',
            'rsi_14' => 55.0,
        ];

        $enabledBullish = $service->qualify($indicators, 65, true);
        $bearishIndicators = array_replace($indicators, ['ema_bullish' => false]);
        $disabledBearish = $service->qualify($bearishIndicators, 65, false);
        $enabledBearish = $service->qualify($bearishIndicators, 65, true);

        $this->assertTrue($enabledBullish['is_qualified']);
        $this->assertTrue($disabledBearish['is_qualified']);
        $this->assertSame('all_conditions_met', $disabledBearish['evaluation_reason']);
        $this->assertFalse($enabledBearish['is_qualified']);
        $this->assertSame('ema_100_not_above_ema_200', $enabledBearish['evaluation_reason']);
    }

    public function test_insufficient_and_invalid_rsi_data_have_explicit_reasons_and_never_pass_as_zero(): void
    {
        $service = new TradingSignalService(new IndicatorCalculator);
        $baseline = ['ema_bullish' => true, 'ha_bullish' => true, 'volume_confirmed' => true];
        $shortCandles = [];
        for ($index = 0; $index < 10; $index++) {
            $close = 100 + $index;
            $shortCandles[] = [
                'open' => $close - 0.5,
                'high' => $close + 1,
                'low' => $close - 1,
                'close' => $close,
                'volume' => 10,
            ];
        }
        $shortHistory = $service->evaluate(new TradingStrategy, $shortCandles);
        $insufficient = $service->qualify($baseline + [
            'rsi_data_status' => $shortHistory['rsi_data_status'],
            'rsi_14' => $shortHistory['rsi_14'],
        ], 65);
        $invalid = $service->qualify($baseline + ['rsi_data_status' => 'invalid_data', 'rsi_14' => null], 65);

        $this->assertSame('insufficient_history', $insufficient['rsi_status']);
        $this->assertSame('insufficient_rsi_history', $insufficient['evaluation_reason']);
        $this->assertSame('invalid_data', $invalid['rsi_status']);
        $this->assertSame('invalid_rsi_data', $invalid['evaluation_reason']);
        $this->assertFalse($invalid['is_qualified']);
    }

    public function test_signal_rsi_uses_regular_close_prices_not_heikin_ashi_closes(): void
    {
        $candles = [];

        for ($index = 0; $index < 30; $index++) {
            $close = 100 + (($index % 3) * 2) + $index;
            $open = $close + ($index % 2 === 0 ? 4 : -3);
            $candles[] = [
                'open' => $open,
                'high' => max($open, $close) + 2,
                'low' => min($open, $close) - 2,
                'close' => $close,
                'volume' => 10.0,
            ];
        }

        $indicators = new IndicatorCalculator;
        $result = (new TradingSignalService($indicators))->evaluate(new TradingStrategy, $candles);
        $regularCloseRsi = $indicators->rsi(array_column($candles, 'close'), 14);
        $heikinAshiCloseRsi = $indicators->rsi(array_column($indicators->heikinAshi($candles), 'close'), 14);

        $this->assertEqualsWithDelta($regularCloseRsi, $result['rsi_14'], 0.000001);
        $this->assertNotEqualsWithDelta($heikinAshiCloseRsi, $result['rsi_14'], 0.000001);
    }

    public function test_rising_ema_does_not_require_a_fresh_crossover(): void
    {
        $signals = (new TradingSignalService(new IndicatorCalculator))->evaluate(
            new TradingStrategy,
            $this->risingCandles(),
        );

        $this->assertTrue($signals['ema_bullish']);
        $this->assertTrue($signals['is_qualified']);
        $this->assertSame('all_conditions_met', $signals['evaluation_reason']);
    }

    public function test_ema_condition_fails_when_ema_100_is_not_above_ema_200(): void
    {
        $signals = (new TradingSignalService(new IndicatorCalculator))->evaluate(
            new TradingStrategy,
            $this->fallingCandles(),
        );

        $this->assertFalse($signals['ema_bullish']);
        $this->assertStringContainsString('ema_100_not_above_ema_200', $signals['evaluation_reason']);
        $this->assertFalse($signals['is_qualified']);
    }

    public function test_latest_heikin_ashi_candle_must_be_green(): void
    {
        $candles = $this->risingCandles();
        $latest = array_key_last($candles);
        $previousClose = $candles[$latest - 1]['close'];
        $candles[$latest] = [
            'open' => $previousClose - 100,
            'high' => $previousClose - 99,
            'low' => $previousClose - 102,
            'close' => $previousClose - 101,
            'volume' => 100.0,
        ];

        $signals = (new TradingSignalService(new IndicatorCalculator))->evaluate(new TradingStrategy, $candles);

        $this->assertFalse($signals['ha_bullish']);
        $this->assertStringContainsString('latest_heikin_ashi_not_green', $signals['evaluation_reason']);
        $this->assertFalse($signals['is_qualified']);
    }

    public function test_current_volume_must_exceed_the_volume_sma(): void
    {
        $candles = $this->risingCandles();
        foreach ($candles as &$candle) {
            $candle['volume'] = 10.0;
        }
        unset($candle);

        $signals = (new TradingSignalService(new IndicatorCalculator))->evaluate(new TradingStrategy, $candles);

        $this->assertFalse($signals['volume_confirmed']);
        $this->assertStringContainsString('volume_not_above_sma', $signals['evaluation_reason']);
        $this->assertFalse($signals['is_qualified']);
    }

    /** @return list<array{open:float,high:float,low:float,close:float,volume:float}> */
    private function risingCandles(): array
    {
        return $this->candles(static fn (int $index): float => 100 + $index, 10.0, 100.0);
    }

    /** @return list<array{open:float,high:float,low:float,close:float,volume:float}> */
    private function fallingCandles(): array
    {
        return $this->candles(static fn (int $index): float => 500 - $index, 10.0, 100.0);
    }

    /** @return list<array{open:float,high:float,low:float,close:float,volume:float}> */
    private function candles(callable $closeAt, float $volume, float $latestVolume): array
    {
        $candles = [];

        for ($index = 0; $index < 400; $index++) {
            $close = $closeAt($index);
            $open = $close - 0.5;
            $candles[] = [
                'open' => $open,
                'high' => $close + 1,
                'low' => $open - 1,
                'close' => $close,
                'volume' => $index === 399 ? $latestVolume : $volume,
            ];
        }

        return $candles;
    }

    /** @param list<float> $changes
     * @return list<float>
     */
    private function closesFromChanges(array $changes): array
    {
        $closes = [100.0];

        foreach ($changes as $change) {
            $closes[] = end($closes) + $change;
        }

        return $closes;
    }
}
