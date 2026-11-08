<?php

namespace Tests\Feature;

use App\Contracts\BybitReadOnlyClient;
use App\Jobs\ScanMarketsJob;
use App\Livewire\ScannerDashboard;
use App\Models\ScanRun;
use App\Models\User;
use App\Services\Trading\FourHourCandleClock;
use App\Services\Trading\ScanRequestService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ManualScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_request_queues_the_existing_scan_job_and_persists_the_closed_candle(): void
    {
        $this->activeStrategy();
        Bus::fake();

        Livewire::test(ScannerDashboard::class)
            ->call('runScanNow')
            ->assertSee('Market scan queued')
            ->assertSee('PHT');

        $run = ScanRun::query()->firstOrFail();

        $this->assertSame('queued', $run->status);
        $this->assertNotNull($run->queued_at);
        $this->assertNotNull($run->candle_at);
        Bus::assertDispatched(ScanMarketsJob::class, fn (ScanMarketsJob $job): bool => $job->scanRunIds === [$run->id]);
        $uniqueLock = app(UniqueLock::class);
        $this->assertFalse($uniqueLock->acquire(new ScanMarketsJob));
        app(UniqueLock::class)->release(new ScanMarketsJob);
    }

    public function test_duplicate_manual_requests_are_rejected_while_scan_is_queued(): void
    {
        $this->activeStrategy();
        Bus::fake();
        $component = Livewire::test(ScannerDashboard::class);

        $component->call('runScanNow')->assertSee('Market scan queued');
        $component->call('runScanNow')->assertSee('already queued or running');

        $this->assertSame(1, ScanRun::query()->count());
        Bus::assertDispatchedTimes(ScanMarketsJob::class, 1);
    }

    public function test_manual_dispatch_respects_an_existing_unique_scan_job_lock(): void
    {
        $this->activeStrategy();
        Bus::fake();
        $uniqueLock = app(UniqueLock::class);
        $existingJob = new ScanMarketsJob([999]);
        $this->assertTrue($uniqueLock->acquire($existingJob));

        try {
            $request = app(ScanRequestService::class)->queueScan();

            $this->assertSame('already_running', $request['status']);

            $this->assertSame(0, ScanRun::query()->count());
            Bus::assertNotDispatched(ScanMarketsJob::class);
        } finally {
            $uniqueLock->release($existingJob);
        }
    }

    public function test_a_new_manual_scan_is_allowed_after_the_previous_scan_completes(): void
    {
        $this->activeStrategy();
        Bus::fake();
        $component = Livewire::test(ScannerDashboard::class);

        $component->call('runScanNow');
        ScanRun::query()->firstOrFail()->update(['status' => 'completed', 'finished_at' => now()]);
        // Bus::fake does not run a worker; simulate the worker releasing Laravel's
        // ShouldBeUnique lock when its previous job completes.
        app(UniqueLock::class)->release(new ScanMarketsJob);
        $component->call('runScanNow')->assertSee('Market scan queued');

        $this->assertSame(2, ScanRun::query()->count());
        Bus::assertDispatchedTimes(ScanMarketsJob::class, 2);
    }

    public function test_manual_request_is_rejected_when_a_scheduled_scan_is_running(): void
    {
        $strategy = $this->activeStrategy();
        Bus::fake();
        $strategy->scanRuns()->create([
            'status' => 'running',
            'started_at' => now(),
        ]);

        Livewire::test(ScannerDashboard::class)
            ->call('runScanNow')
            ->assertSee('already queued or running');

        $this->assertSame(1, ScanRun::query()->count());
        Bus::assertNotDispatched(ScanMarketsJob::class);
    }

    public function test_stale_already_running_notice_clears_when_no_scan_remains_active(): void
    {
        $this->activeStrategy();

        Livewire::test(ScannerDashboard::class)
            ->set('scanNoticeType', 'error')
            ->set('scanNotice', 'A market scan is already queued or running. Wait for it to finish before requesting another scan.')
            ->call('setFilter', 'qualified')
            ->assertDontSee('A market scan is already queued or running.');
    }

    public function test_already_running_notice_remains_while_a_scan_is_active(): void
    {
        $strategy = $this->activeStrategy();
        $strategy->scanRuns()->create([
            'status' => 'queued',
            'queued_at' => now(),
            'started_at' => now(),
        ]);

        Livewire::test(ScannerDashboard::class)
            ->set('scanNoticeType', 'error')
            ->set('scanNotice', 'A market scan is already queued or running. Wait for it to finish before requesting another scan.')
            ->call('setFilter', 'qualified')
            ->assertSee('A market scan is already queued or running.');
    }

    public function test_queue_dispatch_failure_is_reported_and_recorded(): void
    {
        $this->activeStrategy();
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('queue unavailable'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        Livewire::test(ScannerDashboard::class)
            ->call('runScanNow')
            ->assertSee('could not be queued');

        $this->assertDatabaseHas('scan_runs', [
            'status' => 'failed',
            'error_message' => 'dispatch_failed:RuntimeException',
        ]);
    }

    public function test_expired_queued_and_stalled_running_runs_are_failed_before_a_new_scan_is_queued(): void
    {
        config(['trading_scanner.scan_lease_minutes' => 10]);
        Carbon::setTestNow(now('UTC'));
        $strategy = $this->activeStrategy();
        $strategy->scanRuns()->create([
            'status' => 'queued',
            'queued_at' => now()->subMinutes(11),
            'started_at' => now()->subMinutes(11),
        ]);
        $stalledRun = $strategy->scanRuns()->create([
            'status' => 'running',
            'started_at' => now()->subMinutes(11),
        ]);
        ScanRun::query()->whereKey($stalledRun->id)->update(['updated_at' => now()->subMinutes(11)]);
        Bus::fake();

        Livewire::test(ScannerDashboard::class)
            ->call('runScanNow')
            ->assertSee('Market scan queued');

        $this->assertDatabaseHas('scan_runs', [
            'status' => 'failed',
            'error_message' => 'scan_lease_expired',
        ]);
        $this->assertSame(2, ScanRun::query()->where('status', 'failed')->count());
        $this->assertSame(1, ScanRun::query()->where('status', 'queued')->count());

        Carbon::setTestNow();
    }

    public function test_orphaned_scan_run_is_recovered_when_no_queue_job_or_lock_exists(): void
    {
        config([
            'trading_scanner.scan_lease_minutes' => 360,
            'trading_scanner.scan_orphan_grace_minutes' => 5,
        ]);
        Carbon::setTestNow(now('UTC'));
        $strategy = $this->activeStrategy();
        $orphan = $strategy->scanRuns()->create([
            'status' => 'queued',
            'queued_at' => now()->subMinutes(10),
            'started_at' => now()->subMinutes(10),
        ]);
        Bus::fake();

        Livewire::test(ScannerDashboard::class)
            ->call('runScanNow')
            ->assertSee('Market scan queued');

        $this->assertSame('failed', $orphan->fresh()->status);
        $this->assertSame('scan_job_orphaned', $orphan->fresh()->error_message);
        $this->assertSame(1, ScanRun::query()->where('status', 'queued')->count());
        Bus::assertDispatchedTimes(ScanMarketsJob::class, 1);

        Carbon::setTestNow();
    }

    public function test_dispatch_failure_releases_the_request_lock_for_a_later_attempt(): void
    {
        $this->activeStrategy();
        $dispatcher = Mockery::mock(Dispatcher::class);
        $attempts = 0;
        $dispatcher->shouldReceive('dispatch')
            ->twice()
            ->andReturnUsing(static function (ScanMarketsJob $job) use (&$attempts): mixed {
                if (++$attempts === 1) {
                    throw new \RuntimeException('temporary queue failure');
                }

                return null;
            });
        $this->app->instance(Dispatcher::class, $dispatcher);
        $component = Livewire::test(ScannerDashboard::class);

        $component->call('runScanNow')->assertSee('could not be queued');
        $component->call('runScanNow')->assertSee('Market scan queued');

        $this->assertSame(1, ScanRun::query()->where('status', 'failed')->count());
        $this->assertSame(1, ScanRun::query()->where('status', 'queued')->count());
    }

    public function test_exception_during_scan_request_releases_the_request_lock(): void
    {
        $this->activeStrategy();
        $attempts = 0;
        $clock = Mockery::mock(FourHourCandleClock::class);
        $clock->shouldReceive('latestClosedCandleAt')
            ->twice()
            ->andReturnUsing(static function (?int $nowMilliseconds = null) use (&$attempts): Carbon {
                if (++$attempts === 1) {
                    throw new \RuntimeException('temporary clock failure');
                }

                return Carbon::now('UTC');
            });
        $this->app->instance(FourHourCandleClock::class, $clock);
        Bus::fake();
        $component = Livewire::test(ScannerDashboard::class);

        $component->call('runScanNow')->assertSee('could not be processed');
        $component->call('runScanNow')->assertSee('Market scan queued');

        $this->assertSame(1, ScanRun::query()->where('status', 'queued')->count());
        Bus::assertDispatchedTimes(ScanMarketsJob::class, 1);
    }

    public function test_scheduled_artisan_command_still_queues_the_same_scan_job_and_keeps_its_schedule(): void
    {
        $this->activeStrategy();
        Bus::fake();

        $this->assertSame(0, Artisan::call('bybit:scan'));
        Bus::assertDispatched(ScanMarketsJob::class);

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'bybit:scan'));

        $this->assertNotNull($event);
        $this->assertSame('5 */4 * * *', $event->expression);
        $this->assertSame('UTC', $event->timezone);
    }

    public function test_manual_dispatch_does_not_call_bybit_or_expose_order_methods(): void
    {
        $this->activeStrategy();
        Bus::fake();
        Http::fake();

        Livewire::test(ScannerDashboard::class)->call('runScanNow');

        Http::assertNothingSent();
        $methodNames = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(BybitReadOnlyClient::class))->getMethods(),
        );
        $orderMethods = array_filter($methodNames, static fn (string $name): bool => str_contains(strtolower($name), 'order'));

        $this->assertSame([], array_values($orderMethods));
    }

    private function activeStrategy()
    {
        return User::factory()->create()->tradingStrategies()->create([
            'name' => 'Scanner',
            'is_active' => true,
            'minimum_turnover_24h_usdt' => '1000000.00000000',
            'volume_sma_period' => 20,
            'exclude_btc' => true,
            'exclude_eth' => true,
            'exclude_stablecoins' => true,
            'excluded_symbols' => [],
        ]);
    }
}
