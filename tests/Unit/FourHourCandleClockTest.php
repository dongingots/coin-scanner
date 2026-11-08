<?php

namespace Tests\Unit;

use App\Services\Trading\FourHourCandleClock;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FourHourCandleClockTest extends TestCase
{
    public function test_latest_closed_candle_at_1145_pht_is_the_0400_to_0800_pht_candle(): void
    {
        $clock = new FourHourCandleClock;
        $now = Carbon::parse('2026-06-16 11:45:00', 'Asia/Manila')->utc()->getTimestampMs();

        $latestClosedCandle = Carbon::createFromTimestampMs(
            $clock->latestClosedCandleOpenMilliseconds($now),
            'UTC',
        );

        $this->assertSame('2026-06-16 04:00:00', $latestClosedCandle->copy()->setTimezone('Asia/Manila')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-15 20:00:00', $latestClosedCandle->utc()->format('Y-m-d H:i:s'));
    }

    public function test_exact_four_hour_boundary_selects_the_candle_that_just_closed(): void
    {
        $clock = new FourHourCandleClock;
        $now = Carbon::parse('2026-06-16 12:00:00', 'Asia/Manila')->utc()->getTimestampMs();

        $latestClosedCandle = Carbon::createFromTimestampMs(
            $clock->latestClosedCandleOpenMilliseconds($now),
            'UTC',
        );

        $this->assertSame('2026-06-16 08:00:00', $latestClosedCandle->copy()->setTimezone('Asia/Manila')->format('Y-m-d H:i:s'));
    }
}
