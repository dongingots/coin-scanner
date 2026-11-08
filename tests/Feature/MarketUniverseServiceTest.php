<?php

namespace Tests\Feature;

use App\Contracts\BybitReadOnlyClient;
use App\Models\TradingStrategy;
use App\Services\Trading\MarketUniverseService;
use Mockery;
use Tests\TestCase;

class MarketUniverseServiceTest extends TestCase
{
    public function test_instrument_pages_are_fetched_until_the_cursor_is_exhausted_and_tickers_once(): void
    {
        $environment = (string) config('trading_scanner.environment', 'testnet');
        $client = Mockery::mock(BybitReadOnlyClient::class);
        $client->shouldReceive('getLinearInstruments')
            ->once()
            ->with(null, 1000, $environment)
            ->andReturn(['list' => [['symbol' => 'AAAUSDT']], 'nextPageCursor' => 'page-2']);
        $client->shouldReceive('getLinearInstruments')
            ->once()
            ->with('page-2', 1000, $environment)
            ->andReturn(['list' => [['symbol' => 'BBBUSDT']], 'nextPageCursor' => '']);
        $client->shouldReceive('getTickers')
            ->once()
            ->with('linear', null, $environment)
            ->andReturn(['list' => [['symbol' => 'AAAUSDT', 'turnover24h' => '1000']]]);

        $snapshot = (new MarketUniverseService($client))->fetchSnapshot();

        $this->assertCount(2, $snapshot['instruments']);
        $this->assertArrayHasKey('AAAUSDT', $snapshot['tickers']);
    }

    public function test_universe_filters_non_perpetuals_non_usdt_instruments_exclusions_and_liquidity(): void
    {
        $strategy = new TradingStrategy([
            'minimum_turnover_24h_usdt' => '100.00000000',
            'excluded_symbols' => ['CUSTOMUSDT'],
            'exclude_btc' => true,
            'exclude_eth' => true,
            'exclude_stablecoins' => true,
        ]);
        $snapshot = [
            'instruments' => [
                $this->instrument('BTCUSDT', 'BTC'),
                $this->instrument('ETHUSDT', 'ETH'),
                $this->instrument('USDCUSDT', 'USDC'),
                $this->instrument('CUSTOMUSDT', 'CUSTOM'),
                $this->instrument('SPOTLIKEUSDT', 'SPOTLIKE', 'Spot'),
                $this->instrument('INACTIVEUSDT', 'INACTIVE', 'LinearPerpetual', 'Settling'),
                $this->instrument('ALTUSDC', 'ALT', 'LinearPerpetual', 'Trading', 'USDC', 'USDC'),
                $this->instrument('ALTUSDT', 'ALT'),
                $this->instrument('LOWUSDT', 'LOW'),
                $this->instrument('MISSINGUSDT', 'MISSING'),
            ],
            'tickers' => [
                'ALTUSDT' => ['turnover24h' => '250.50'],
                'LOWUSDT' => ['turnover24h' => '99.99'],
            ],
        ];

        $candidates = (new MarketUniverseService(Mockery::mock(BybitReadOnlyClient::class)))
            ->candidatesFor($strategy, $snapshot, $strategy->minimum_turnover_24h_usdt);

        $this->assertSame(['ALTUSDT', 'LOWUSDT', 'MISSINGUSDT'], array_column($candidates, 'symbol'));
        $this->assertTrue($candidates[0]['can_evaluate']);
        $this->assertSame('turnover_below_configured_minimum', $candidates[1]['reason']);
        $this->assertSame('ticker_turnover_unavailable', $candidates[2]['reason']);
    }

    public function test_no_liquidity_cutoff_is_assumed_when_strategy_threshold_is_null(): void
    {
        $strategy = new TradingStrategy(['exclude_btc' => false, 'exclude_eth' => false, 'exclude_stablecoins' => false]);
        $snapshot = [
            'instruments' => [$this->instrument('ALTUSDT', 'ALT')],
            'tickers' => ['ALTUSDT' => ['turnover24h' => '0.01']],
        ];

        $candidate = (new MarketUniverseService(Mockery::mock(BybitReadOnlyClient::class)))
            ->candidatesFor($strategy, $snapshot, $strategy->minimum_turnover_24h_usdt)[0];

        $this->assertNull($strategy->minimum_turnover_24h_usdt);
        $this->assertTrue($candidate['can_evaluate']);
    }

    private function instrument(
        string $symbol,
        string $base,
        string $contractType = 'LinearPerpetual',
        string $status = 'Trading',
        string $quote = 'USDT',
        string $settle = 'USDT',
    ): array {
        return [
            'symbol' => $symbol,
            'baseCoin' => $base,
            'quoteCoin' => $quote,
            'settleCoin' => $settle,
            'contractType' => $contractType,
            'status' => $status,
        ];
    }
}
