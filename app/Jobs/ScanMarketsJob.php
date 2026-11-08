<?php

namespace App\Jobs;

use App\Models\ScanRun;
use App\Models\ScannedPair;
use App\Models\TradingSignal;
use App\Models\TradingStrategy;
use App\Services\Trading\MarketUniverseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

class ScanMarketsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 3600;

    public function __construct()
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

    public function handle(MarketUniverseService $universe): void
    {
        $strategies = TradingStrategy::query()->where('is_active', true)->get();

        if ($strategies->isEmpty()) {
            return;
        }

        $runs = $strategies->map(fn (TradingStrategy $strategy) => $strategy->scanRuns()->create([
            'status' => 'running',
            'started_at' => now(),
        ]));

        try {
            $snapshot = $universe->fetchSnapshot();

            foreach ($strategies as $index => $strategy) {
                /** @var ScanRun $run */
                $run = $runs[$index];
                $strategy->scannedPairs()->update(['is_active' => false]);
                $candidates = $universe->candidatesFor($strategy, $snapshot);
                $run->update([
                    'instrument_count' => count($snapshot['instruments']),
                    'pair_count' => count($candidates),
                ]);

                $candleOpenMs = $this->latestClosedCandleOpenMs();
                $candleAt = Carbon::createFromTimestampMs($candleOpenMs, 'UTC');

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

                    if (! $candidate['can_evaluate']) {
                        $signal = $pair->signals()->firstOrCreate(
                            ['timeframe' => '4H', 'candle_at' => $candleAt],
                            [
                                'is_qualified' => false,
                                'evaluation_reason' => $candidate['reason'],
                                'evaluated_at' => now(),
                            ],
                        );

                        $run->recordResult($signal->is_qualified);

                        continue;
                    }

                    EvaluateSymbolJob::dispatch($pair->id, $run->id, $candleOpenMs)
                        ->onQueue('bybit-market-data');
                }

                $run->finishIfSettled();
            }
        } catch (Throwable $exception) {
            foreach ($runs as $run) {
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

    private function latestClosedCandleOpenMs(): int
    {
        $intervalMs = (int) config('trading_scanner.candle_interval_ms', 14_400_000);
        $currentOpenMs = intdiv(now('UTC')->getTimestampMs(), $intervalMs) * $intervalMs;

        return $currentOpenMs - $intervalMs;
    }
}
