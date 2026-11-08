<?php

namespace App\Services\Trading;

use InvalidArgumentException;

class IndicatorCalculator
{
    /**
     * Calculate an SMA-seeded EMA. The first EMA is the arithmetic mean of the
     * first `period` closes; each following close uses alpha = 2 / (period + 1).
     *
     * @param list<float|int|string> $values
     */
    public function ema(array $values, int $period): ?float
    {
        if ($period < 1) {
            throw new InvalidArgumentException('EMA period must be positive.');
        }

        if (count($values) < $period) {
            return null;
        }

        $seedValues = array_map('floatval', array_slice($values, 0, $period));
        $ema = array_sum($seedValues) / $period;
        $alpha = 2 / ($period + 1);

        foreach (array_slice($values, $period) as $value) {
            $ema = ((float) $value * $alpha) + ($ema * (1 - $alpha));
        }

        return $ema;
    }

    /**
     * @param list<array{open:float,high:float,low:float,close:float}> $candles
     * @return list<array{open:float,high:float,low:float,close:float}>
     */
    public function heikinAshi(array $candles): array
    {
        if ($candles === []) {
            return [];
        }

        $result = [];
        $previousOpen = ($candles[0]['open'] + $candles[0]['close']) / 2;
        $previousClose = ($candles[0]['open'] + $candles[0]['high'] + $candles[0]['low'] + $candles[0]['close']) / 4;

        foreach ($candles as $index => $candle) {
            $haClose = ($candle['open'] + $candle['high'] + $candle['low'] + $candle['close']) / 4;
            $haOpen = $index === 0 ? $previousOpen : ($previousOpen + $previousClose) / 2;

            $result[] = [
                'open' => $haOpen,
                'high' => max($candle['high'], $haOpen, $haClose),
                'low' => min($candle['low'], $haOpen, $haClose),
                'close' => $haClose,
            ];

            $previousOpen = $haOpen;
            $previousClose = $haClose;
        }

        return $result;
    }

    /**
     * Volume SMA includes the current closed candle and the prior period - 1
     * closed candles, matching the usual charting-platform SMA convention.
     *
     * @param list<float|int|string> $volumes
     */
    public function volumeSma(array $volumes, int $period): ?float
    {
        if ($period < 1) {
            throw new InvalidArgumentException('Volume SMA period must be positive.');
        }

        if (count($volumes) < $period) {
            return null;
        }

        return array_sum(array_map('floatval', array_slice($volumes, -$period))) / $period;
    }
}
