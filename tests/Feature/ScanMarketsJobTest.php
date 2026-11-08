<?php

namespace Tests\Feature;

use App\Contracts\BybitReadOnlyClient;
use App\Jobs\EvaluateSymbolJob;
use App\Jobs\ScanMarketsJob;
use App\Models\ScanRun;
use App\Models\TradingSignal;
use App\Models\User;
use App\Services\Trading\MarketUniverseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class ScanMarketsJobTest extends TestCase
{
    use RefreshDatabase;

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

        (new ScanMarketsJob)->handle(new MarketUniverseService($client));

        $this->assertDatabaseCount('scanned_pairs', 2);
        $this->assertDatabaseHas('scanned_pairs', ['symbol' => 'ALTUSDT', 'is_active' => true]);
        $this->assertDatabaseHas('scanned_pairs', ['symbol' => 'LOWUSDT', 'is_active' => true]);
        $this->assertDatabaseMissing('scanned_pairs', ['symbol' => 'BTCUSDT']);
        $this->assertDatabaseHas('trading_signals', [
            'evaluation_reason' => 'turnover_below_configured_minimum',
            'is_qualified' => false,
        ]);
        $this->assertSame(1, ScanRun::query()->firstOrFail()->not_qualified_count);
        $this->assertSame('running', ScanRun::query()->firstOrFail()->status);
        Bus::assertDispatched(EvaluateSymbolJob::class, fn (EvaluateSymbolJob $job): bool =>
            $job->scannedPairId === 1 && $job->scanRunId === 1
        );
        $this->assertSame(1, TradingSignal::query()->count());
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
