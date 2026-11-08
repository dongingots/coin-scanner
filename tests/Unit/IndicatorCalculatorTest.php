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
}
