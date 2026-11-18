<?php

namespace App\Services\Trading;

use App\Jobs\ScanMarketsJob;
use App\Models\ScanRun;
use App\Models\TradingStrategy;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

class ScanRequestService
{
    public function __construct(
        private readonly Dispatcher $bus,
        private readonly FourHourCandleClock $candleClock,
        private readonly UniqueLock $uniqueLock,
    ) {}

    /**
     * Queue one global scan request for all active strategies.
     *
     * @return array{status:string,run_ids?:list<int>,candle_at?:string,active_status?:string}
     */
    public function queueScan(): array
    {
        try {
            $lock = Cache::lock('bybit-market-scan:dispatch', 30);

            if (! $lock->get()) {
                return ['status' => 'already_running'];
            }
        } catch (Throwable) {
            return ['status' => 'dispatch_failed'];
        }

        $uniqueLockAcquired = false;

        try {
            $request = DB::transaction(function () use (&$uniqueLockAcquired): array {
                $staleBefore = now('UTC')->subMinutes(max(1, (int) config('trading_scanner.scan_lease_minutes', 360)));

                $staleRuns = ScanRun::query()
                    ->where(function (Builder $query) use ($staleBefore): void {
                        $query->where(function (Builder $queued) use ($staleBefore): void {
                            $queued->where('status', 'queued')
                                ->whereRaw('COALESCE(queued_at, created_at) <= ?', [$staleBefore]);
                        })->orWhere(function (Builder $running) use ($staleBefore): void {
                            $running->where('status', 'running')
                                ->where('updated_at', '<=', $staleBefore);
                        });
                    })
                    ->lockForUpdate()
                    ->get();

                foreach ($staleRuns as $staleRun) {
                    $staleRun->update([
                        'status' => 'failed',
                        'finished_at' => now(),
                        'error_message' => 'scan_lease_expired',
                    ]);
                }

                // A queued ScanRun can outlive a lost queue payload or a worker crash.
                // Recover it after a short grace period only when neither the unique job
                // lock nor a queued/reserved job exists. Fail closed if Redis is unavailable.
                if (! $this->hasOutstandingScanJob()) {
                    $orphanedBefore = now('UTC')->subMinutes(max(
                        1,
                        (int) config('trading_scanner.scan_orphan_grace_minutes', 5),
                    ));

                    ScanRun::query()
                        ->whereIn('status', ['queued', 'running'])
                        ->whereRaw('COALESCE(queued_at, started_at, created_at) <= ?', [$orphanedBefore])
                        ->lockForUpdate()
                        ->get()
                        ->each(fn (ScanRun $run) => $run->update([
                            'status' => 'failed',
                            'finished_at' => now(),
                            'error_message' => 'scan_job_orphaned',
                        ]));
                }

                $activeRuns = ScanRun::query()
                    ->whereIn('status', ['queued', 'running'])
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->get();

                if ($activeRuns->isNotEmpty()) {
                    return [
                        'status' => 'already_running',
                        'active_status' => $activeRuns->first()->status,
                        'candle_at' => $activeRuns->first()->candle_at?->toIso8601String(),
                    ];
                }

                $strategies = TradingStrategy::query()
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($strategies->isEmpty()) {
                    return ['status' => 'no_active_strategy'];
                }

                // Direct Dispatcher::dispatch() bypasses PendingDispatch, where Laravel
                // normally acquires ShouldBeUnique locks. Acquire it explicitly after
                // the database active-run check so stale-run recovery can still inspect
                // pre-existing queue state.
                if (! $this->uniqueLock->acquire(new ScanMarketsJob)) {
                    return ['status' => 'already_running'];
                }

                $uniqueLockAcquired = true;

                $now = now('UTC');
                $candleAt = $this->candleClock->latestClosedCandleAt($now->getTimestampMs());
                $runIds = [];

                foreach ($strategies as $strategy) {
                    $run = $strategy->scanRuns()->create([
                        'status' => 'queued',
                        'queued_at' => $now,
                        'started_at' => $now,
                        'candle_at' => $candleAt,
                        'maximum_coin_price_snapshot' => $strategy->maximum_coin_price,
                    ]);

                    $runIds[] = $run->id;
                }

                return [
                    'status' => 'queued',
                    'run_ids' => $runIds,
                    'candle_at' => $candleAt->toIso8601String(),
                ];
            });

            if ($request['status'] !== 'queued') {
                return $request;
            }

            try {
                $this->bus->dispatch(new ScanMarketsJob($request['run_ids']));
            } catch (Throwable $exception) {
                ScanRun::query()->whereIn('id', $request['run_ids'])
                    ->where('status', 'queued')
                    ->update([
                        'status' => 'failed',
                        'finished_at' => now(),
                        'error_message' => 'dispatch_failed:'.class_basename($exception),
                    ]);

                return ['status' => 'dispatch_failed'];
            }

            // The queue worker releases the unique-job lock after handling/failure.
            $uniqueLockAcquired = false;

            return $request;
        } finally {
            if ($uniqueLockAcquired) {
                try {
                    $this->uniqueLock->release(new ScanMarketsJob);
                } catch (Throwable) {
                    // Keep the original scan request result; the lock also has a TTL.
                }
            }

            $lock->release();
        }
    }

    private function hasOutstandingScanJob(): bool
    {
        try {
            $job = new ScanMarketsJob;

            if (Cache::has(UniqueLock::getKey($job))) {
                return true;
            }

            return Queue::connection()->size($job->queue ?? 'bybit-market-data') > 0;
        } catch (Throwable) {
            return true;
        }
    }
}
