<?php

namespace App\Services\Trading;

use Illuminate\Support\Carbon;

class FourHourCandleClock
{
    public function latestClosedCandleOpenMilliseconds(?int $nowMilliseconds = null): int
    {
        $intervalMilliseconds = (int) config('trading_scanner.candle_interval_ms', 14_400_000);
        $nowMilliseconds ??= now('UTC')->getTimestampMs();

        if ($intervalMilliseconds < 1) {
            throw new \InvalidArgumentException('The scanner candle interval must be positive.');
        }

        return intdiv($nowMilliseconds, $intervalMilliseconds) * $intervalMilliseconds - $intervalMilliseconds;
    }

    public function latestClosedCandleAt(?int $nowMilliseconds = null): Carbon
    {
        return Carbon::createFromTimestampMs($this->latestClosedCandleOpenMilliseconds($nowMilliseconds), 'UTC');
    }
}
