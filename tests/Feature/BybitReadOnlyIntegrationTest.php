<?php

namespace Tests\Feature;

use App\Contracts\BybitReadOnlyClient;
use App\Exceptions\BybitApiException;
use App\Exceptions\BybitRateLimitException;
use App\Models\ExchangeAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BybitReadOnlyIntegrationTest extends TestCase
{
    public function test_tickers_are_fetched_from_the_default_testnet(): void
    {
        Http::fake([
            'api-testnet.bybit.com/v5/market/tickers*' => Http::response([
                'retCode' => 0,
                'retMsg' => 'OK',
                'result' => ['category' => 'linear', 'list' => [['symbol' => 'BTCUSDT', 'lastPrice' => '65000.10']]],
            ]),
        ]);

        $result = $this->app->make(BybitReadOnlyClient::class)->getTickers('linear', 'BTCUSDT');

        $this->assertSame('65000.10', $result['list'][0]['lastPrice']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api-testnet.bybit.com/v5/market/tickers?category=linear&symbol=BTCUSDT'
            && ! $request->hasHeader('X-BAPI-API-KEY')
        );
    }

    public function test_linear_instruments_expose_a_bybit_page_cursor_for_pagination(): void
    {
        Http::fake([
            'api-testnet.bybit.com/v5/market/instruments-info*' => Http::response([
                'retCode' => 0,
                'retMsg' => 'OK',
                'result' => [
                    'list' => [['symbol' => 'ALTUSDT', 'status' => 'Trading']],
                    'nextPageCursor' => 'instrument-page-2',
                ],
            ]),
        ]);

        $result = $this->app->make(BybitReadOnlyClient::class)->getLinearInstruments();

        $this->assertSame('instrument-page-2', $result['nextPageCursor']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://api-testnet.bybit.com/v5/market/instruments-info?')
            && str_contains($request->url(), 'category=linear')
            && str_contains($request->url(), 'status=Trading')
            && str_contains($request->url(), 'limit=1000')
        );
    }

    public function test_candles_map_configured_timeframes_and_use_the_live_market_url_only_when_selected(): void
    {
        Http::fake([
            'api.bybit.com/v5/market/kline*' => Http::response([
                'retCode' => 0,
                'retMsg' => 'OK',
                'result' => ['category' => 'linear', 'symbol' => 'BTCUSDT', 'list' => [['1760000000000', '1', '2', '1', '2', '3', '4']]],
            ]),
        ]);

        $result = $this->app->make(BybitReadOnlyClient::class)->getKlines(
            symbol: 'BTCUSDT', interval: '1H', category: 'linear', start: 1760000000000, end: 1760003600000,
            limit: 100, environment: 'mainnet',
        );

        $this->assertCount(1, $result['list']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://api.bybit.com/v5/market/kline?')
            && str_contains($request->url(), 'interval=60')
            && str_contains($request->url(), 'start=1760000000000')
            && str_contains($request->url(), 'end=1760003600000')
        );
    }

    public function test_wallet_balance_is_authenticated_and_uses_the_account_environment(): void
    {
        Http::fake([
            'api-testnet.bybit.com/v5/account/wallet-balance*' => Http::response([
                'retCode' => 0,
                'retMsg' => 'OK',
                'result' => ['list' => [['accountType' => 'UNIFIED', 'totalWalletBalance' => '125.5000']]],
            ]),
        ]);

        $result = $this->app->make(BybitReadOnlyClient::class)->getWalletBalance(
            $this->account(), 'UNIFIED', 'USDT',
        );

        $this->assertSame('125.5000', $result['list'][0]['totalWalletBalance']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->hasHeader('X-BAPI-API-KEY', 'fake-testnet-key')
            && $request->hasHeader('X-BAPI-SIGN')
            && str_contains($request->url(), 'accountType=UNIFIED&coin=USDT')
        );
    }

    public function test_open_positions_fetches_linear_positions_by_settlement_coin(): void
    {
        Http::fake([
            'api-testnet.bybit.com/v5/position/list*' => Http::response([
                'retCode' => 0,
                'retMsg' => 'OK',
                'result' => ['category' => 'linear', 'list' => [['symbol' => 'BTCUSDT', 'side' => 'Buy', 'size' => '0.01']]],
            ]),
        ]);

        $result = $this->app->make(BybitReadOnlyClient::class)->getOpenPositions(
            $this->account(), category: 'linear', settleCoin: 'USDT', limit: 10, cursor: 'next-page-token',
        );

        $this->assertSame('0.01', $result['list'][0]['size']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), 'category=linear&settleCoin=USDT&limit=10&cursor=next-page-token')
            && $request->hasHeader('X-BAPI-API-KEY', 'fake-testnet-key')
        );
    }

    public function test_rate_limited_requests_are_retried_then_return_success(): void
    {
        config(['bybit.max_retries' => 1, 'bybit.retry_delay_ms' => 0]);
        Http::fakeSequence()
            ->push(['retCode' => 10006, 'retMsg' => 'Too many visits'], 200)
            ->push(['retCode' => 0, 'retMsg' => 'OK', 'result' => ['list' => []]], 200);

        $this->app->make(BybitReadOnlyClient::class)->getWalletBalance($this->account());

        Http::assertSentCount(2);
    }

    public function test_http_429_rate_limits_are_retried(): void
    {
        config(['bybit.max_retries' => 1, 'bybit.retry_delay_ms' => 0]);
        Http::fakeSequence()
            ->push(['retCode' => 0, 'retMsg' => 'rate limited'], 429)
            ->push(['retCode' => 0, 'retMsg' => 'OK', 'result' => ['list' => []]], 200);

        $this->app->make(BybitReadOnlyClient::class)->getWalletBalance($this->account());

        Http::assertSentCount(2);
    }

    public function test_rate_limit_exhaustion_raises_a_safe_exception(): void
    {
        config(['bybit.max_retries' => 0, 'bybit.retry_delay_ms' => 0]);
        Http::fake(['*' => Http::response(['retCode' => 10006, 'retMsg' => 'Too many visits'], 200)]);

        try {
            $this->app->make(BybitReadOnlyClient::class)->getWalletBalance($this->account());
            $this->fail('Expected a rate limit exception.');
        } catch (BybitRateLimitException $exception) {
            $this->assertSame(10006, $exception->retCode);
            $this->assertStringNotContainsString('fake-testnet-secret', $exception->getMessage());
        }
    }

    public function test_api_errors_and_timeouts_are_reported_without_credentials(): void
    {
        Http::fake(['*' => Http::response(['retCode' => 10003, 'retMsg' => 'API key is invalid'], 200)]);

        try {
            $this->app->make(BybitReadOnlyClient::class)->getWalletBalance($this->account());
            $this->fail('Expected an API exception.');
        } catch (BybitApiException $exception) {
            $this->assertSame(10003, $exception->retCode);
            $this->assertStringNotContainsString('fake-testnet-secret', $exception->getMessage());
        }

        config(['bybit.max_retries' => 0]);
        Http::fake(fn () => throw new ConnectionException('simulated timeout'));

        try {
            $this->app->make(BybitReadOnlyClient::class)->getWalletBalance($this->account());
            $this->fail('Expected a connection exception.');
        } catch (BybitApiException $exception) {
            $this->assertStringContainsString('timed out', $exception->getMessage());
            $this->assertStringNotContainsString('fake-testnet-secret', $exception->getMessage());
        }
    }

    private function account(): ExchangeAccount
    {
        $account = new ExchangeAccount;
        $account->environment = 'testnet';
        $account->api_key = 'fake-testnet-key';
        $account->api_secret = 'fake-testnet-secret';

        return $account;
    }
}
