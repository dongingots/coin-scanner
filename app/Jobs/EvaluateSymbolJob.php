<?php

namespace App\Jobs;

use App\Contracts\BybitReadOnlyClient;
use App\Exceptions\CandleDataException;
use App\Models\ScanRun;
use App\Models\ScannedPair;
use App\Models\TradingSignal;
use App\Services\Trading\CandleDataNormalizer;
use App\Services\Trading\TradingSignalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
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

    public function __construct(
        public readonly int $scannedPairId,
        public readonly int $scanRunId,
        public readonly int $candleOpenMs,
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
        $pair = ScannedPair::query()->with('strategy')->findOrFail($this->scannedPairId);
        $run = ScanRun::query()->findOrFail($this->scanRunId);
        $candleAt = Carbon::createFromTimestampMs($this->candleOpenMs, 'UTC');
        $existing = $pair->signals()->where('timeframe', '4H')->where('candle_at', $candleAt)->first();

        if ($existing !== null) {
            $run->recordResult($existing->is_qualified);

            return;
        }

        $requiredCount = max(
            400,
            (int) config('trading_scanner.candle_history_limit', 400),
            (int) $pair->strategy->volume_sma_period,
        );

        if ($requiredCount > 999) {
            $this->recordNotQualified($pair, $run, $candleAt, 'required_history_exceeds_bybit_limit');

            return;
        }

        try {
            $result = $bybit->getKlines(
                symbol: $pair->symbol,
                interval: '4H',
                category: 'linear',
                limit: min(1000, $requiredCount + 1),
                environment: (string) config('trading_scanner.environment', 'testnet'),
            );
            $rows = $result['list'] ?? null;

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
            $this->recordNotQualified($pair, $run, $candleAt, $exception->reason);

            return;
        }

        $latestCandle = $candles[array_key_last($candles)];

        if ($latestCandle['timestamp'] !== $this->candleOpenMs) {
            $this->recordNotQualified($pair, $run, $candleAt, 'latest_closed_candle_does_not_match_scan');

            return;
        }

        $evaluation = $signalService->evaluate($pair->strategy, $candles);
        $signal = $pair->signals()->firstOrCreate(
            ['timeframe' => '4H', 'candle_at' => $candleAt],
            $evaluation + ['evaluated_at' => now()],
        );

        $run->recordResult($signal->is_qualified);
    }

    public function failed(Throwable $exception): void
    {
        $pair = ScannedPair::query()->find($this->scannedPairId);
        $run = ScanRun::query()->find($this->scanRunId);

        if ($pair !== null) {
            $candleAt = Carbon::createFromTimestampMs($this->candleOpenMs, 'UTC');
            $pair->signals()->firstOrCreate(
                ['timeframe' => '4H', 'candle_at' => $candleAt],
                [
                    'is_qualified' => false,
                    'evaluation_reason' => 'evaluation_failed:'.class_basename($exception),
                    'evaluated_at' => now(),
                ],
            );
        }

        if ($run !== null && $run->status === 'running') {
            $run->recordFailure('symbol_evaluation_failed:'.class_basename($exception));
        }
    }

    private function recordNotQualified(ScannedPair $pair, ScanRun $run, Carbon $candleAt, string $reason): void
    {
        $signal = $pair->signals()->firstOrCreate(
            ['timeframe' => '4H', 'candle_at' => $candleAt],
            [
                'is_qualified' => false,
                'evaluation_reason' => $reason,
                'evaluated_at' => now(),
            ],
        );

        $run->recordResult($signal->is_qualified);
    }
}
