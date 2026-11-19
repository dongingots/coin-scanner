<?php

namespace App\Jobs;

use App\Models\ScannedPair;
use App\Models\ScanRun;
use App\Models\ScanRunPairResult;
use App\Models\TradingStrategy;
use App\Services\Trading\FourHourCandleClock;
use App\Services\Trading\MarketUniverseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

class ScanMarketsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 3600;

    /** @param list<int> $scanRunIds */
    public function __construct(public array $scanRunIds = [])
    {
        $this->onQueue('bybit-market-data');
    }

    public function uniqueId(): string
    {
        return 'bybit-usdt-perpetual-market-scan';
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('bybit-usdt-perpetual-market-scan'))->expireAfter(3600)->releaseAfter(30),
            (new RateLimited('bybit-market-data'))->releaseAfter(1),
        ];
    }

    public function handle(MarketUniverseService $universe, FourHourCandleClock $candleClock): void
    {
        $candleOpenMs = $candleClock->latestClosedCandleOpenMilliseconds();
        $candleAt = Carbon::createFromTimestampMs($candleOpenMs, 'UTC');
        $runsByStrategy = collect();

        if ($this->scanRunIds === []) {
            $strategies = TradingStrategy::query()->where('is_active', true)->get();

            foreach ($strategies as $strategy) {
                $runsByStrategy->put($strategy->id, $strategy->scanRuns()->create([
                    'status' => 'running',
                    'started_at' => now(),
                    'candle_at' => $candleAt,
                    'minimum_turnover_snapshot' => $strategy->minimum_turnover_24h_usdt,
                    'maximum_coin_price_snapshot' => $strategy->maximum_coin_price,
                    'ema_trend_filter_enabled_snapshot' => $strategy->ema_trend_filter_enabled,
                    'rsi_maximum_snapshot' => $strategy->rsi_maximum,
                ]));
            }
        } else {
            $runsByStrategy = ScanRun::query()
                ->whereIn('id', $this->scanRunIds)
                ->where('status', 'queued')
                ->get()
                ->keyBy('trading_strategy_id');

            $strategies = TradingStrategy::query()
                ->where('is_active', true)
                ->whereIn('id', $runsByStrategy->keys())
                ->get();

            $inactiveRunIds = $runsByStrategy->keys()->diff($strategies->modelKeys())
                ->map(fn (int $strategyId): int => $runsByStrategy->get($strategyId)->id)
                ->all();

            if ($inactiveRunIds !== []) {
                ScanRun::query()->whereIn('id', $inactiveRunIds)->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'error_message' => 'strategy_inactive_before_scan',
                ]);
            }

            foreach ($strategies as $strategy) {
                $run = $runsByStrategy->get($strategy->id);
                $run->update([
                    'status' => 'running',
                    'started_at' => now(),
                    'candle_at' => $candleAt,
                    'minimum_turnover_snapshot' => $strategy->minimum_turnover_24h_usdt,
                    'maximum_coin_price_snapshot' => $run->maximum_coin_price_snapshot ?? $strategy->maximum_coin_price,
                    'ema_trend_filter_enabled_snapshot' => $run->ema_trend_filter_enabled_snapshot ?? $strategy->ema_trend_filter_enabled,
                    'rsi_maximum_snapshot' => $strategy->rsi_maximum,
                ]);
            }
        }

        if ($strategies->isEmpty()) {
            return;
        }

        try {
            $snapshot = $universe->fetchSnapshot();

            foreach ($strategies as $strategy) {
                /** @var ScanRun $run */
                $run = $runsByStrategy->get($strategy->id);
                $strategy->scannedPairs()->update(['is_active' => false]);
                $candidates = $universe->candidatesFor(
                    $strategy,
                    $snapshot,
                    $run->minimum_turnover_snapshot,
                    $run->maximum_coin_price_snapshot,
                );
                $run->update([
                    'instrument_count' => count($snapshot['instruments']),
                    'pair_count' => count($candidates),
                ]);

                foreach ($candidates as $candidate) {
                    /** @var ScannedPair $pair */
                    $pair = $strategy->scannedPairs()->updateOrCreate(
                        ['symbol' => $candidate['symbol']],
                        [
                            'base_coin' => $candidate['base_coin'],
                            'quote_coin' => $candidate['quote_coin'],
                            'contract_type' => $candidate['contract_type'],
                            'turnover_24h_usdt' => $candidate['turnover'],
                            'is_active' => true,
                            'last_scanned_at' => now(),
                        ],
                    );

                    $pairResult = $run->pairResults()->firstOrCreate(
                        ['scanned_pair_id' => $pair->id],
                        [
                            'turnover_24h_snapshot' => $candidate['turnover'],
                            'minimum_turnover_snapshot' => $run->minimum_turnover_snapshot,
                            'status' => ScanRunPairResult::STATUS_PENDING,
                        ],
                    );

                    if (! $candidate['can_evaluate']) {
                        $pairResult->complete(false, $candidate['reason'] ?? 'candidate_not_eligible');

                        continue;
                    }

                    if ($pairResult->status === ScanRunPairResult::STATUS_PENDING) {
                        EvaluateSymbolJob::dispatch($pair->id, $run->id, $candleOpenMs, $pairResult->id)
                            ->onQueue('bybit-market-data');
                    }
                }

                $run->finishIfSettled();
            }
        } catch (Throwable $exception) {
            foreach ($runsByStrategy as $run) {
                if ($run->fresh()?->status === 'running') {
                    $run->update([
                        'status' => 'failed',
                        'finished_at' => now(),
                        'error_message' => class_basename($exception),
                    ]);
                }
            }

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        if ($this->scanRunIds === []) {
            return;
        }

        ScanRun::query()->whereIn('id', $this->scanRunIds)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => 'scan_job_failed:'.class_basename($exception),
            ]);
    }
}
