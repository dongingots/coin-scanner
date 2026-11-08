<?php

namespace Tests\Feature;

use App\Livewire\ScannerDashboard;
use App\Models\ScannedPair;
use App\Models\TradingStrategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->pairWithSignal($strategy, 'ALPHAUSDT', true);
        $this->pairWithSignal($strategy, 'BETAUSDT', false);

        Livewire::test(ScannerDashboard::class)
            ->assertSee('Altcoins scanned')
            ->assertSee('ALPHAUSDT')
            ->assertSee('BETAUSDT')
            ->assertSee('Qualified')
            ->assertSee('Not qualified')
            ->call('setFilter', 'qualified')
            ->assertSee('ALPHAUSDT')
            ->assertDontSee('BETAUSDT')
            ->call('setFilter', 'not_qualified')
            ->assertSee('BETAUSDT')
            ->assertDontSee('ALPHAUSDT');
    }

    public function test_dashboard_shows_empty_state_when_no_strategy_is_active(): void
    {
        Livewire::test(ScannerDashboard::class)->assertSee('No active strategy');
    }

    private function pairWithSignal(TradingStrategy $strategy, string $symbol, bool $qualified): ScannedPair
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
        $pair->signals()->create([
            'timeframe' => '4H',
            'candle_at' => now()->startOfDay(),
            'ema_100' => '200.000000000000',
            'ema_200' => '180.000000000000',
            'ha_open' => '199.000000000000',
            'ha_close' => $qualified ? '201.000000000000' : '198.000000000000',
            'volume' => $qualified ? '20.000000000000' : '10.000000000000',
            'volume_sma' => '15.000000000000',
            'ema_bullish' => true,
            'ha_bullish' => $qualified,
            'volume_confirmed' => $qualified,
            'is_qualified' => $qualified,
            'evaluation_reason' => $qualified ? 'all_conditions_met' : 'latest_heikin_ashi_not_green;volume_not_above_sma',
            'evaluated_at' => now(),
        ]);

        return $pair;
    }
}
