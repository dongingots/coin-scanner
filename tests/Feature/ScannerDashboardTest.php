<?php

namespace Tests\Feature;

use App\Jobs\ScanMarketsJob;
use App\Livewire\ScannerDashboard;
use App\Models\ScannedPair;
use App\Models\TradingStrategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class ScannerDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_counts_and_filters_qualified_pairs(): void
    {
        $strategy = User::factory()->create()->tradingStrategies()->create([
            'name' => 'Scanner',
            'is_active' => true,
        ]);
        $run = $strategy->scanRuns()->create([
            'status' => 'completed',
            'pair_count' => 2,
            'evaluated_count' => 2,
            'qualified_count' => 1,
            'not_qualified_count' => 1,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'candle_at' => now()->startOfDay(),
            'minimum_turnover_snapshot' => '250000.00000000',
            'rsi_maximum_snapshot' => '65.00',
        ]);
        $this->pairWithSignal($strategy, $run, 'ALPHAUSDT', true);
        $this->pairWithSignal($strategy, $run, 'BETAUSDT', false);

        Livewire::test(ScannerDashboard::class)
            ->assertSee('Altcoins scanned')
            ->assertSee('250,000.00000000 USDT')
            ->assertSee('#1 · Completed')
            ->assertSee('ALPHAUSDT')
            ->assertSee('BETAUSDT')
            ->assertSee('Qualified')
            ->assertSee('Not qualified')
            ->assertSee('RSI (14) · 4H')
            ->assertSee('RSI 14 maximum at scan: 65.00')
            ->assertSee('55.0000')
            ->assertSee('Passed')
            ->call('setFilter', 'qualified')
            ->assertSee('ALPHAUSDT')
            ->assertDontSee('BETAUSDT')
            ->call('setFilter', 'not_qualified')
            ->assertSee('BETAUSDT')
            ->assertDontSee('ALPHAUSDT');
    }

    public function test_dashboard_can_sort_run_scoped_results_by_related_pair_symbol(): void
    {
        $strategy = User::factory()->create()->tradingStrategies()->create([
            'name' => 'Scanner',
            'is_active' => true,
        ]);
        $oldRun = $strategy->scanRuns()->create([
            'status' => 'completed',
            'pair_count' => 1,
            'evaluated_count' => 1,
            'qualified_count' => 1,
            'started_at' => now()->subMinutes(3),
            'finished_at' => now()->subMinutes(2),
        ]);
        $this->pairWithSignal($strategy, $oldRun, 'OLDERUSDT', true);

        $run = $strategy->scanRuns()->create([
            'status' => 'completed',
            'pair_count' => 2,
            'evaluated_count' => 2,
            'qualified_count' => 2,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
        $this->pairWithSignal($strategy, $run, 'ZULUUSDT', true);
        $this->pairWithSignal($strategy, $run, 'ALPHAUSDT', true);

        Livewire::test(ScannerDashboard::class)
            ->assertSeeInOrder(['ALPHAUSDT', 'ZULUUSDT'])
            ->assertDontSee('OLDERUSDT');
    }

    public function test_queued_scan_shows_waiting_state_and_hides_stale_results(): void
    {
        $strategy = User::factory()->create()->tradingStrategies()->create([
            'name' => 'Scanner',
            'is_active' => true,
            'minimum_turnover_24h_usdt' => '500000.00000000',
        ]);
        $previousRun = $strategy->scanRuns()->create([
            'status' => 'completed',
            'pair_count' => 1,
            'evaluated_count' => 1,
            'qualified_count' => 1,
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHour(),
        ]);
        $this->pairWithSignal($strategy, $previousRun, 'STALEUSDT', true);
        $queuedRun = $strategy->scanRuns()->create([
            'status' => 'queued',
            'queued_at' => now()->subSeconds(35),
            'started_at' => now()->subSeconds(35),
            'candle_at' => now()->subHours(4),
        ]);

        Livewire::test(ScannerDashboard::class)
            ->assertSee('Waiting for a queue worker')
            ->assertSee('Scan run')
            ->assertSee('#'.$queuedRun->id)
            ->assertSee('500,000.00000000 USDT')
            ->assertSee('run snapshot pending')
            ->assertSee('wire:poll.4s')
            ->assertDontSee('Altcoins scanned')
            ->assertDontSee('STALEUSDT');
    }

    public function test_running_scan_shows_scanning_state_and_hides_stale_results(): void
    {
        $strategy = User::factory()->create()->tradingStrategies()->create([
            'name' => 'Scanner',
            'is_active' => true,
        ]);
        $previousRun = $strategy->scanRuns()->create([
            'status' => 'completed',
            'pair_count' => 1,
            'evaluated_count' => 1,
            'qualified_count' => 1,
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHour(),
        ]);
        $this->pairWithSignal($strategy, $previousRun, 'STALEUSDT', true);
        $runningRun = $strategy->scanRuns()->create([
            'status' => 'running',
            'queued_at' => now()->subMinute(),
            'started_at' => now()->subSeconds(30),
            'candle_at' => now()->subHours(4),
            'minimum_turnover_snapshot' => '500000.00000000',
        ]);

        Livewire::test(ScannerDashboard::class)
            ->assertSee('Scanning Bybit Markets')
            ->assertSee('#'.$runningRun->id)
            ->assertSee('500,000.00000000 USDT')
            ->assertSee('scan snapshot')
            ->assertSee('wire:poll.4s')
            ->assertDontSee('Altcoins scanned')
            ->assertDontSee('STALEUSDT');
    }

    public function test_failed_scan_shows_error_and_retry_without_stale_results(): void
    {
        $strategy = User::factory()->create()->tradingStrategies()->create([
            'name' => 'Scanner',
            'is_active' => true,
        ]);
        $previousRun = $strategy->scanRuns()->create([
            'status' => 'completed',
            'pair_count' => 1,
            'evaluated_count' => 1,
            'qualified_count' => 1,
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHour(),
        ]);
        $this->pairWithSignal($strategy, $previousRun, 'STALEUSDT', true);
        $strategy->scanRuns()->create([
            'status' => 'failed',
            'queued_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'error_message' => 'upstream_api:timeout',
        ]);

        Livewire::test(ScannerDashboard::class)
            ->assertSee('The market scan could not finish')
            ->assertSee('upstream api: timeout')
            ->assertSee('Try a new scan')
            ->assertDontSee('Altcoins scanned')
            ->assertDontSee('STALEUSDT')
            ->assertDontSee('wire:poll.4s');
    }

    public function test_poll_refresh_does_not_dispatch_another_scan_job(): void
    {
        $strategy = User::factory()->create()->tradingStrategies()->create([
            'name' => 'Scanner',
            'is_active' => true,
        ]);
        $strategy->scanRuns()->create([
            'status' => 'queued',
            'queued_at' => now(),
            'started_at' => now(),
        ]);
        Bus::fake();

        Livewire::test(ScannerDashboard::class)
            ->call('$refresh')
            ->assertSee('Waiting for a queue worker');

        Bus::assertNotDispatched(ScanMarketsJob::class);
    }

    public function test_refresh_replaces_active_scan_state_with_completed_run_results(): void
    {
        $strategy = User::factory()->create()->tradingStrategies()->create([
            'name' => 'Scanner',
            'is_active' => true,
        ]);
        $run = $strategy->scanRuns()->create([
            'status' => 'queued',
            'queued_at' => now(),
            'started_at' => now(),
        ]);
        Bus::fake();
        $dashboard = Livewire::test(ScannerDashboard::class)
            ->assertSee('Waiting for a queue worker');

        $this->pairWithSignal($strategy, $run, 'FRESHUSDT', true);
        $run->update([
            'status' => 'completed',
            'pair_count' => 1,
            'evaluated_count' => 1,
            'qualified_count' => 1,
            'finished_at' => now(),
        ]);

        $dashboard->call('$refresh')
            ->assertSee('Altcoins scanned')
            ->assertSee('FRESHUSDT')
            ->assertDontSee('Scanning Bybit Markets')
            ->assertDontSee('wire:poll.4s');

        Bus::assertNotDispatched(ScanMarketsJob::class);
    }

    public function test_dashboard_shows_empty_state_when_no_strategy_is_active(): void
    {
        Livewire::test(ScannerDashboard::class)->assertSee('No active strategy');
    }

    public function test_dashboard_displays_rsi_rejection_and_insufficient_history_reasons(): void
    {
        $strategy = User::factory()->create()->tradingStrategies()->create([
            'name' => 'Scanner',
            'is_active' => true,
        ]);
        $run = $strategy->scanRuns()->create([
            'status' => 'completed',
            'pair_count' => 2,
            'evaluated_count' => 2,
            'not_qualified_count' => 2,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'rsi_maximum_snapshot' => '65.00',
        ]);
        $rejectedPair = $this->pairWithSignal($strategy, $run, 'HIGHRSIUSDT', false);
        $rejectedSignal = $rejectedPair->signals()->firstOrFail();
        $rejectedPair->scanRunResults()->firstOrFail()->update([
            'is_qualified' => false,
            'rsi_status' => 'rejected',
            'evaluation_reason' => 'rsi_above_maximum',
        ]);
        $rejectedSignal->update(['rsi_14' => '70.0000']);

        $insufficientPair = $this->pairWithSignal($strategy, $run, 'SHORTDATAUSDT', false);
        $insufficientSignal = $insufficientPair->signals()->firstOrFail();
        $insufficientPair->scanRunResults()->firstOrFail()->update([
            'is_qualified' => false,
            'rsi_status' => 'insufficient_history',
            'evaluation_reason' => 'insufficient_rsi_history',
        ]);
        $insufficientSignal->update(['rsi_14' => null, 'rsi_data_status' => 'insufficient_history']);

        Livewire::test(ScannerDashboard::class)
            ->assertSee('70.0000')
            ->assertSee('Rejected')
            ->assertSee('rsi_above_maximum')
            ->assertSee('Insufficient history')
            ->assertSee('insufficient_rsi_history');
    }

    public function test_legacy_run_does_not_show_unscoped_historical_signals_as_its_results(): void
    {
        $strategy = User::factory()->create()->tradingStrategies()->create([
            'name' => 'Scanner',
            'is_active' => true,
        ]);
        $this->pairWithSignal($strategy, null, 'OLDUSDT', true);
        $strategy->scanRuns()->create([
            'status' => 'completed',
            'pair_count' => 1,
            'evaluated_count' => 1,
            'qualified_count' => 1,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        Livewire::test(ScannerDashboard::class)
            ->assertSee('Historical results are unavailable for this scan')
            ->assertDontSee('OLDUSDT');
    }

    private function pairWithSignal(TradingStrategy $strategy, $run, string $symbol, bool $qualified): ScannedPair
    {
        $pair = $strategy->scannedPairs()->create([
            'symbol' => $symbol,
            'base_coin' => str_replace('USDT', '', $symbol),
            'quote_coin' => 'USDT',
            'contract_type' => 'LinearPerpetual',
            'turnover_24h_usdt' => '1000.00000000',
            'is_active' => true,
            'last_scanned_at' => now(),
        ]);
        $signal = $pair->signals()->create([
            'timeframe' => '4H',
            'candle_at' => now()->startOfDay(),
            'ema_100' => '200.000000000000',
            'ema_200' => '180.000000000000',
            'ha_open' => '199.000000000000',
            'ha_close' => $qualified ? '201.000000000000' : '198.000000000000',
            'volume' => $qualified ? '20.000000000000' : '10.000000000000',
            'volume_sma' => '15.000000000000',
            'rsi_14' => '55.0000',
            'rsi_data_status' => 'valid',
            'ema_bullish' => true,
            'ha_bullish' => $qualified,
            'volume_confirmed' => $qualified,
            'is_qualified' => $qualified,
            'evaluation_reason' => $qualified ? 'all_conditions_met' : 'latest_heikin_ashi_not_green;volume_not_above_sma',
            'evaluated_at' => now(),
        ]);

        if ($run !== null) {
            $run->pairResults()->create([
                'scanned_pair_id' => $pair->id,
                'indicator_signal_id' => $signal->id,
                'turnover_24h_snapshot' => '750000.00000000',
                'minimum_turnover_snapshot' => '250000.00000000',
                'rsi_status' => 'passed',
                'status' => 'evaluated',
                'is_qualified' => $qualified,
                'evaluation_reason' => $qualified ? 'all_conditions_met' : 'latest_heikin_ashi_not_green;volume_not_above_sma',
                'evaluated_at' => now(),
            ]);
        }

        return $pair;
    }
}
