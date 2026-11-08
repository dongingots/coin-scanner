<?php

namespace App\Services\Trading;

use App\Models\TradingStrategy;

class TradingSignalService
{
    public function __construct(private readonly IndicatorCalculator $indicators) {}

    /**
     * Evaluate a normalized, oldest-first series of closed candles.
     *
     * @param list<array{open:float,high:float,low:float,close:float,volume:float}> $candles
     * @return array<string, float|bool|null|string>
     */
    public function evaluate(TradingStrategy $strategy, array $candles): array
    {
        $closes = array_column($candles, 'close');
        $volumes = array_column($candles, 'volume');
        $ema100 = $this->indicators->ema($closes, 100);
        $ema200 = $this->indicators->ema($closes, 200);
        $volumeSma = $this->indicators->volumeSma($volumes, (int) $strategy->volume_sma_period);
        $heikinAshi = $this->indicators->heikinAshi($candles);
        $latestCandle = $candles[array_key_last($candles)];
        $latestHa = $heikinAshi[array_key_last($heikinAshi)];

        $emaBullish = $ema100 !== null && $ema200 !== null && $ema100 > $ema200;
        $haBullish = $latestHa['close'] > $latestHa['open'];
        $volumeConfirmed = $volumeSma !== null && $latestCandle['volume'] > $volumeSma;

        $reasons = [];

        if (! $emaBullish) {
            $reasons[] = 'ema_100_not_above_ema_200';
        }

        if (! $haBullish) {
            $reasons[] = 'latest_heikin_ashi_not_green';
        }

        if (! $volumeConfirmed) {
            $reasons[] = 'volume_not_above_sma';
        }

        return [
            'ema_100' => $ema100,
            'ema_200' => $ema200,
            'ha_open' => $latestHa['open'],
            'ha_close' => $latestHa['close'],
            'volume' => $latestCandle['volume'],
            'volume_sma' => $volumeSma,
            'ema_bullish' => $emaBullish,
            'ha_bullish' => $haBullish,
            'volume_confirmed' => $volumeConfirmed,
            'is_qualified' => $emaBullish && $haBullish && $volumeConfirmed,
            'evaluation_reason' => $reasons === [] ? 'all_conditions_met' : implode(';', $reasons),
        ];
    }
}
