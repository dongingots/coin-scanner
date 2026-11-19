<?php

namespace App\Jobs;

use App\Contracts\BybitReadOnlyClient;
use App\Exceptions\CandleDataException;
use App\Models\ScannedPair;
use App\Models\ScanRun;
use App\Models\ScanRunPairResult;
use App\Models\TradingSignal;
use App\Services\Trading\CandleDataNormalizer;
use App\Services\Trading\TradingSignalService;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

class EvaluateSymbolJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public array $backoff = [10, 30, 60];

    /**
     * Rate-limited queue releases count as attempts. Give the shared market-data
     * queue time to drain large scans instead of failing jobs after three releases.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function __construct(
        public readonly int $scannedPairId,
        public readonly int $scanRunId,
        public readonly int $candleOpenMs,
        public readonly ?int $scanRunPairResultId = null,
    ) {
        $this->onQueue('bybit-market-data');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('bybit-signal:'.$this->scannedPairId.':'.$this->candleOpenMs))
                ->expireAfter(600)
                ->releaseAfter(10),
            (new RateLimited('bybit-market-data'))->releaseAfter(1),
        ];
    }

    public function handle(
        BybitReadOnlyClient $bybit,
        CandleDataNormalizer $normalizer,
        TradingSignalService $signalService,
    ): void {
        $run = ScanRun::query()->findOrFail($this->scanRunId);

        if ($run->status !== 'running') {
            return;
        }

        $pair = ScannedPair::query()->with('strategy')->findOrFail($this->scannedPairId);
        $pairResult = $this->pairResult($run, $pair);

        if ($pairResult->status !== ScanRunPairResult::STATUS_PENDING) {
            return;
        }

        $rsiMaximumSnapshot = $run->rsi_maximum_snapshot;

        if ($rsiMaximumSnapshot === null) {
            $rsiMaximumSnapshot = $pair->strategy->rsi_maximum;
            $run->update(['rsi_maximum_snapshot' => $rsiMaximumSnapshot]);
        }

        $emaTrendFilterEnabledSnapshot = $run->ema_trend_filter_enabled_snapshot;

        if ($emaTrendFilterEnabledSnapshot === null) {
            $emaTrendFilterEnabledSnapshot = $pair->strategy->ema_trend_filter_enabled;
            $run->update(['ema_trend_filter_enabled_snapshot' => $emaTrendFilterEnabledSnapshot]);
        }

        $minimumTurnover = $run->minimum_turnover_snapshot;
        $pairResult->update(['minimum_turnover_snapshot' => $minimumTurnover]);

        if ($pairResult->turnover_24h_snapshot === null) {
            $pairResult->complete(false, 'ticker_turnover_unavailable');

            return;
        }

        if ($minimumTurnover !== null && (float) $pairResult->turnover_24h_snapshot < (float) $minimumTurnover) {
            $pairResult->complete(false, 'turnover_below_configured_minimum');

            return;
        }

        $candleAt = Carbon::createFromTimestampMs($this->candleOpenMs, 'UTC');
        $signal = $pair->signals()->where('timeframe', '4H')->where('candle_at', $candleAt)->first();

        if (! $this->hasReusableIndicatorValues($signal)) {
            $requiredCount = max(
                400,
                (int) config('trading_scanner.candle_history_limit', 400),
                (int) $pair->strategy->volume_sma_period,
                TradingSignalService::RSI_PERIOD + 1,
            );

            if ($requiredCount > 999) {
                $pairResult->complete(false, 'required_history_exceeds_bybit_limit');

                return;
            }

            try {
                $response = $bybit->getKlines(
                    symbol: $pair->symbol,
                    interval: '4H',
                    category: 'linear',
                    limit: min(1000, $requiredCount + 1),
                    environment: (string) config('trading_scanner.environment', 'testnet'),
                );
                $rows = $response['list'] ?? null;

                if (! is_array($rows)) {
                    throw new CandleDataException('candle_list_missing');
                }

                $candles = $normalizer->normalizeClosed(
                    $rows,
                    now('UTC')->getTimestampMs(),
                    $requiredCount,
                    (int) config('trading_scanner.candle_interval_ms', 14_400_000),
                );
            } catch (CandleDataException $exception) {
                if ($exception->reason === 'insufficient_closed_candles') {
                    $pairResult->complete(
                        false,
                        'insufficient_rsi_history',
                        rsiStatus: ScanRunPairResult::RSI_INSUFFICIENT_HISTORY,
                    );
                } elseif (in_array($exception->reason, ['candle_list_missing', 'missing_or_non_contiguous_candles'], true)) {
                    $pairResult->complete(
                        false,
                        'invalid_rsi_data',
                        rsiStatus: ScanRunPairResult::RSI_INVALID_DATA,
                    );
                } else {
                    $pairResult->complete(false, $exception->reason);
                }

                return;
            }

            $latestCandle = $candles[array_key_last($candles)];

            if ($latestCandle['timestamp'] !== $this->candleOpenMs) {
                $pairResult->complete(false, 'latest_closed_candle_does_not_match_scan');

                return;
            }

            $evaluation = $signalService->evaluate($pair->strategy, $candles);
            $signal = $pair->signals()->updateOrCreate(
                ['timeframe' => '4H', 'candle_at' => $candleAt],
                $evaluation + ['evaluated_at' => now()],
            );
        }

        $qualification = $signalService->qualify(
            $signal->toArray(),
            (float) $rsiMaximumSnapshot,
            (bool) $emaTrendFilterEnabledSnapshot,
        );
        $pairResult->complete(
            $qualification['is_qualified'],
            $qualification['evaluation_reason'],
            $signal->id,
            $qualification['rsi_status'],
        );
    }

    public function failed(Throwable $exception): void
    {
        $run = ScanRun::query()->find($this->scanRunId);

        if ($run === null || $run->status !== 'running') {
            return;
        }

        $pair = ScannedPair::query()->find($this->scannedPairId);

        if ($pair === null) {
            return;
        }

        $result = $this->scanRunPairResultId !== null
            ? ScanRunPairResult::query()->where('scan_run_id', $run->id)->find($this->scanRunPairResultId)
            : ScanRunPairResult::query()->where('scan_run_id', $run->id)->where('scanned_pair_id', $pair->id)->first();

        if ($result !== null) {
            $result->fail('symbol_evaluation_failed:'.class_basename($exception));
        }
    }

    private function pairResult(ScanRun $run, ScannedPair $pair): ScanRunPairResult
    {
        if ($this->scanRunPairResultId !== null) {
            return ScanRunPairResult::query()
                ->where('scan_run_id', $run->id)
                ->where('scanned_pair_id', $pair->id)
                ->findOrFail($this->scanRunPairResultId);
        }

        return $run->pairResults()->firstOrCreate(
            ['scanned_pair_id' => $pair->id],
            [
                'turnover_24h_snapshot' => $pair->turnover_24h_usdt,
                'minimum_turnover_snapshot' => $run->minimum_turnover_snapshot,
                'status' => ScanRunPairResult::STATUS_PENDING,
            ],
        );
    }

    private function hasReusableIndicatorValues(?TradingSignal $signal): bool
    {
        if ($signal === null) {
            return false;
        }

        foreach (['ema_100', 'ema_200', 'ha_open', 'ha_close', 'volume', 'volume_sma', 'ema_bullish', 'ha_bullish', 'volume_confirmed'] as $attribute) {
            if ($signal->{$attribute} === null) {
                return false;
            }
        }

        if (! in_array($signal->rsi_data_status, ['valid', 'insufficient_history', 'invalid_data'], true)) {
            return false;
        }

        return $signal->rsi_data_status !== 'valid' || $signal->rsi_14 !== null;
    }
}
