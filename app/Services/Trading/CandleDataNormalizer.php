<?php

namespace App\Services\Trading;

use App\Exceptions\CandleDataException;

class CandleDataNormalizer
{
    /**
     * Convert Bybit's newest-first candle tuples into a contiguous, oldest-first
     * series containing closed, valid candles only.
     *
     * @param array<int, mixed> $rows
     * @return list<array{timestamp:int,open:float,high:float,low:float,close:float,volume:float}>
     */
    public function normalizeClosed(array $rows, int $asOfMs, int $requiredCount, int $intervalMs): array
    {
        $candlesByTimestamp = [];

        foreach ($rows as $row) {
            if (! is_array($row) || count($row) < 6 || ! is_numeric($row[0] ?? null)) {
                continue;
            }

            $timestamp = (int) $row[0];

            if ($timestamp < 1 || $timestamp + $intervalMs > $asOfMs) {
                continue;
            }

            $values = array_slice($row, 1, 5);

            if (count($values) !== 5 || ! collect($values)->every(fn ($value): bool => is_numeric($value) && is_finite((float) $value))) {
                continue;
            }

            [$open, $high, $low, $close, $volume] = array_map('floatval', $values);

            if ($open <= 0 || $high <= 0 || $low <= 0 || $close <= 0 || $volume < 0) {
                continue;
            }

            if ($high < max($open, $close, $low) || $low > min($open, $close, $high)) {
                continue;
            }

            $candlesByTimestamp[$timestamp] = compact('timestamp', 'open', 'high', 'low', 'close', 'volume');
        }

        ksort($candlesByTimestamp, SORT_NUMERIC);
        $candles = array_values($candlesByTimestamp);

        if (count($candles) < $requiredCount) {
            throw new CandleDataException('insufficient_closed_candles');
        }

        $candles = array_slice($candles, -$requiredCount);

        for ($index = 1, $count = count($candles); $index < $count; $index++) {
            if ($candles[$index]['timestamp'] - $candles[$index - 1]['timestamp'] !== $intervalMs) {
                throw new CandleDataException('missing_or_non_contiguous_candles');
            }
        }

        return $candles;
    }
}
