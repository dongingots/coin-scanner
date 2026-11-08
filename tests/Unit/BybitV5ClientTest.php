<?php

namespace Tests\Unit;

use App\Contracts\BybitReadOnlyClient;
use App\Models\ExchangeAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class BybitV5ClientTest extends TestCase
{
    public function test_private_request_uses_v5_hmac_sha256_signature(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestampMs(1760000000123, 'UTC'));
        config(['bybit.recv_window' => 5000]);

        Http::fake(fn () => Http::response([
            'retCode' => 0,
            'retMsg' => 'OK',
            'result' => ['list' => []],
        ]));

        $account = $this->account('testnet', 'fake-api-key', 'fake-api-secret');
        $this->app->make(BybitReadOnlyClient::class)->getWalletBalance($account, 'UNIFIED', 'USDT');

        $expected = hash_hmac(
            'sha256',
            '1760000000123fake-api-key5000accountType=UNIFIED&coin=USDT',
            'fake-api-secret',
        );

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api-testnet.bybit.com/v5/account/wallet-balance?accountType=UNIFIED&coin=USDT'
            && $request->hasHeader('X-BAPI-API-KEY', 'fake-api-key')
            && $request->hasHeader('X-BAPI-TIMESTAMP', '1760000000123')
            && $request->hasHeader('X-BAPI-RECV-WINDOW', '5000')
            && $request->hasHeader('X-BAPI-SIGN', $expected)
        );

        Carbon::setTestNow();
    }

    public function test_unknown_environment_is_rejected_before_a_request_is_sent(): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidArgumentException::class);

        $this->app->make(BybitReadOnlyClient::class)->getTickers('linear', null, 'production');
    }

    private function account(string $environment, string $apiKey, string $apiSecret): ExchangeAccount
    {
        $account = new ExchangeAccount;
        $account->environment = $environment;
        $account->api_key = $apiKey;
        $account->api_secret = $apiSecret;

        return $account;
    }
}
