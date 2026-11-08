<?php

namespace Tests\Unit;

use App\Exceptions\CandleDataException;
use App\Services\Trading\CandleDataNormalizer;
use PHPUnit\Framework\TestCase;

class CandleDataNormalizerTest extends TestCase
{
    public function test_incomplete_candles_are_excluded_and_remaining_series_is_ascending(): void
    {
        $normalizer = new CandleDataNormalizer;
        $rows = [
            ['3000', '12', '13', '11', '12.5', '20', '250'],
            ['2000', '11', '12', '10', '11.5', '10', '200'],
            ['1000', '10', '11', '9', '10.5', '10', '150'],
        ];

        $candles = $normalizer->normalizeClosed($rows, asOfMs: 3500, requiredCount: 2, intervalMs: 1000);

        $this->assertSame([1000, 2000], array_column($candles, 'timestamp'));
        $this->assertSame(10.0, $candles[0]['open']);
        $this->assertSame(10.0, $candles[0]['volume']);
    }

    public function test_gaps_in_the_required_candle_window_are_rejected(): void
    {
        $this->expectException(CandleDataException::class);
        $this->expectExceptionMessage('missing_or_non_contiguous_candles');

        (new CandleDataNormalizer)->normalizeClosed([
            ['3000', '12', '13', '11', '12.5', '20'],
            ['1000', '10', '11', '9', '10.5', '10'],
        ], asOfMs: 5000, requiredCount: 2, intervalMs: 1000);
    }

    public function test_invalid_candle_values_are_not_used(): void
    {
        $this->expectException(CandleDataException::class);
        $this->expectExceptionMessage('insufficient_closed_candles');

        (new CandleDataNormalizer)->normalizeClosed([
            ['1000', '10', '9', '11', '10', '10'],
            ['2000', 'bad', '12', '10', '11', '10'],
        ], asOfMs: 4000, requiredCount: 2, intervalMs: 1000);
    }
}
