<?php

namespace App\Services\Trading;

use App\Models\TradingStrategy;

class TradingSignalService
{
    public const RSI_PERIOD = 14;

    public function __construct(private readonly IndicatorCalculator $indicators) {}

    /**
     * Evaluate a normalized, oldest-first series of closed candles.
     *
     * @param  list<array{open:float,high:float,low:float,close:float,volume:float}>  $candles
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
        $rsi = $this->indicators->rsi($closes, self::RSI_PERIOD);
        $validCloses = $this->hasValidCloses($closes);
        $rsiDataStatus = 'valid';

        if (! $validCloses || ($rsi === null && count($closes) >= self::RSI_PERIOD + 1)) {
            $rsiDataStatus = 'invalid_data';
        } elseif (count($closes) < self::RSI_PERIOD + 1) {
            $rsiDataStatus = 'insufficient_history';
        }
        $latestCandle = $candles === [] ? null : $candles[array_key_last($candles)];
        $latestHa = $heikinAshi === [] ? null : $heikinAshi[array_key_last($heikinAshi)];

        $emaBullish = $ema100 !== null && $ema200 !== null && $ema100 > $ema200;
        $haBullish = $latestHa !== null && $latestHa['close'] > $latestHa['open'];
        $volumeConfirmed = $volumeSma !== null && $latestCandle !== null && $latestCandle['volume'] > $volumeSma;

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
            'ha_open' => $latestHa['open'] ?? null,
            'ha_close' => $latestHa['close'] ?? null,
            'volume' => $latestCandle['volume'] ?? null,
            'volume_sma' => $volumeSma,
            'rsi_14' => $rsi,
            'rsi_data_status' => $rsiDataStatus,
            'ema_bullish' => $emaBullish,
            'ha_bullish' => $haBullish,
            'volume_confirmed' => $volumeConfirmed,
            'is_qualified' => $emaBullish && $haBullish && $volumeConfirmed,
            'evaluation_reason' => $reasons === [] ? 'all_conditions_met' : implode(';', $reasons),
        ];
    }

    /**
     * Apply run-specific qualification to reusable candle indicators.
     * RSI threshold decisions deliberately live on the ScanRun result, not on
     * the shared candle signal, because the configured maximum can change.
     *
     * @param  array<string, mixed>  $indicators
     * @return array{is_qualified:bool,evaluation_reason:string,rsi_status:string}
     */
    public function qualify(array $indicators, float $rsiMaximum): array
    {
        $reasons = [];

        if (! ($indicators['ema_bullish'] ?? false)) {
            $reasons[] = 'ema_100_not_above_ema_200';
        }

        if (! ($indicators['ha_bullish'] ?? false)) {
            $reasons[] = 'latest_heikin_ashi_not_green';
        }

        if (! ($indicators['volume_confirmed'] ?? false)) {
            $reasons[] = 'volume_not_above_sma';
        }

        $rsi = $indicators['rsi_14'] ?? null;
        $dataStatus = $indicators['rsi_data_status'] ?? 'invalid_data';

        if ($dataStatus === 'insufficient_history') {
            $rsiStatus = 'insufficient_history';
            $reasons[] = 'insufficient_rsi_history';
        } elseif ($dataStatus !== 'valid' || ! is_numeric($rsi) || ! is_finite((float) $rsi) || (float) $rsi < 0 || (float) $rsi > 100) {
            $rsiStatus = 'invalid_data';
            $reasons[] = 'invalid_rsi_data';
        } elseif ((float) $rsi > $rsiMaximum) {
            $rsiStatus = 'rejected';
            $reasons[] = 'rsi_above_maximum';
        } else {
            $rsiStatus = 'passed';
        }

        return [
            'is_qualified' => $reasons === [],
            'evaluation_reason' => $reasons === [] ? 'all_conditions_met' : implode(';', $reasons),
            'rsi_status' => $rsiStatus,
        ];
    }

    /** @param list<mixed> $closes */
    private function hasValidCloses(array $closes): bool
    {
        foreach ($closes as $close) {
            if (! is_numeric($close) || ! is_finite((float) $close) || (float) $close <= 0) {
                return false;
            }
        }

        return true;
    }
}
