<?php

namespace Tests\Feature;

use App\Contracts\BybitReadOnlyClient;
use App\Jobs\EvaluateSymbolJob;
use App\Jobs\ScanMarketsJob;
use App\Models\ScanRun;
use App\Models\TradingSignal;
use App\Models\User;
use App\Services\Trading\FourHourCandleClock;
use App\Services\Trading\MarketUniverseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class ScanMarketsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_redis_reservation_timeout_exceeds_the_scan_job_timeout(): void
    {
        $this->assertGreaterThan(
            (new ScanMarketsJob)->timeout,
            (int) config('queue.connections.redis.retry_after'),
        );
    }

    public function test_scan_jobs_are_routed_to_the_market_data_queue_on_the_default_connection(): void
    {
        config(['queue.default' => 'redis']);
        $job = new ScanMarketsJob;

        $this->assertSame('bybit-market-data', $job->queue);
        $this->assertNull($job->connection);
        $this->assertSame('redis', config('queue.default'));
        $this->assertSame('default', config('queue.connections.redis.queue'));
    }

    public function test_failed_scan_job_marks_its_running_run_as_failed(): void
    {
        $strategy = User::factory()->create()->tradingStrategies()->create([
            'name' => 'Scanner',
            'is_active' => true,
        ]);
        $run = $strategy->scanRuns()->create([
            'status' => 'running',
            'started_at' => now(),
        ]);

        (new ScanMarketsJob([$run->id]))->failed(new \RuntimeException('worker attempts exhausted'));

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertNotNull($run->fresh()->finished_at);
    }

    public function test_manual_queued_run_is_started_and_uses_the_latest_closed_candle_at_execution(): void
    {
        $strategy = User::factory()->create()->tradingStrategies()->create([
            'name' => 'Scanner',
            'is_active' => true,
        ]);
        $run = $strategy->scanRuns()->create([
            'status' => 'queued',
            'queued_at' => now(),
            'started_at' => now(),
            'candle_at' => now()->subHours(4),
        ]);
        $client = Mockery::mock(BybitReadOnlyClient::class);
        $client->shouldReceive('getLinearInstruments')
            ->once()
            ->andReturn(['list' => [], 'nextPageCursor' => '']);
        $client->shouldReceive('getTickers')
            ->once()
            ->andReturn(['list' => []]);

        (new ScanMarketsJob([$run->id]))->handle(new MarketUniverseService($client), new FourHourCandleClock);

        $run->refresh();
        $expectedCandleAt = Carbon::createFromTimestampMs(
            app(FourHourCandleClock::class)->latestClosedCandleOpenMilliseconds(),
            'UTC',
        );

        $this->assertSame('completed', $run->status);
        $this->assertTrue($run->started_at->greaterThanOrEqualTo($run->queued_at));
        $this->assertTrue($run->candle_at->equalTo($expectedCandleAt));
        $this->assertSame(1, ScanRun::query()->count());
    }

    public function test_scan_job_stores_liquidity_failures_and_queues_only_candle_eligible_pairs(): void
    {
        $strategy = User::factory()->create()->tradingStrategies()->create([
            'name' => 'Scanner',
            'is_active' => true,
            'minimum_turnover_24h_usdt' => '100.00000000',
        ]);
        $client = Mockery::mock(BybitReadOnlyClient::class);
        $client->shouldReceive('getLinearInstruments')
            ->once()
            ->andReturn(['list' => [
                $this->instrument('ALTUSDT', 'ALT'),
                $this->instrument('LOWUSDT', 'LOW'),
                $this->instrument('BTCUSDT', 'BTC'),
            ], 'nextPageCursor' => '']);
        $client->shouldReceive('getTickers')
            ->once()
            ->andReturn(['list' => [
                ['symbol' => 'ALTUSDT', 'turnover24h' => '250.00'],
                ['symbol' => 'LOWUSDT', 'turnover24h' => '99.00'],
                ['symbol' => 'BTCUSDT', 'turnover24h' => '1000000.00'],
            ]]);
        Bus::fake([EvaluateSymbolJob::class]);

        (new ScanMarketsJob)->handle(new MarketUniverseService($client), new FourHourCandleClock);

        $this->assertDatabaseCount('scanned_pairs', 2);
        $this->assertDatabaseHas('scanned_pairs', ['symbol' => 'ALTUSDT', 'is_active' => true]);
        $this->assertDatabaseHas('scanned_pairs', ['symbol' => 'LOWUSDT', 'is_active' => true]);
        $this->assertDatabaseMissing('scanned_pairs', ['symbol' => 'BTCUSDT']);
        $this->assertDatabaseHas('scan_run_pair_results', [
            'evaluation_reason' => 'turnover_below_configured_minimum',
            'is_qualified' => false,
            'minimum_turnover_snapshot' => '100.00000000',
        ]);
        $this->assertSame(1, ScanRun::query()->firstOrFail()->not_qualified_count);
        $this->assertSame('running', ScanRun::query()->firstOrFail()->status);
        Bus::assertDispatched(
            EvaluateSymbolJob::class,
            fn (EvaluateSymbolJob $job): bool => $job->scannedPairId === 1 && $job->scanRunId === 1,
        );
        $this->assertSame(0, TradingSignal::query()->count());
    }

    private function instrument(string $symbol, string $base): array
    {
        return [
            'symbol' => $symbol,
            'baseCoin' => $base,
            'quoteCoin' => 'USDT',
            'settleCoin' => 'USDT',
            'contractType' => 'LinearPerpetual',
            'status' => 'Trading',
        ];
    }
}
