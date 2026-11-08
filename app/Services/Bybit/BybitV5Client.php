<?php

namespace App\Services\Bybit;

use App\Contracts\BybitReadOnlyClient;
use App\Exceptions\BybitApiException;
use App\Exceptions\BybitRateLimitException;
use App\Models\ExchangeAccount;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class BybitV5Client implements BybitReadOnlyClient
{
    private const TICKERS_PATH = '/v5/market/tickers';

    private const INSTRUMENTS_PATH = '/v5/market/instruments-info';

    private const KLINES_PATH = '/v5/market/kline';

    private const WALLET_BALANCE_PATH = '/v5/account/wallet-balance';

    private const POSITIONS_PATH = '/v5/position/list';

    private const CATEGORIES = ['spot', 'linear', 'inverse', 'option'];

    public function __construct(
        private readonly Factory $http,
        private readonly Repository $config,
    ) {}

    public function getTickers(string $category = 'linear', ?string $symbol = null, ?string $environment = null): array
    {
        $this->validateCategory($category, self::CATEGORIES);
        $params = ['category' => $category];

        if ($symbol !== null) {
            $params['symbol'] = $this->requiredText($symbol, 'symbol');
        }

        return $this->request($environment ?? $this->config->get('bybit.default_environment', 'testnet'), self::TICKERS_PATH, $params);
    }

    public function getLinearInstruments(?string $cursor = null, int $limit = 1000, ?string $environment = null): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Instrument limit must be between 1 and 1000.');
        }

        $params = ['category' => 'linear', 'status' => 'Trading', 'limit' => $limit];

        if ($cursor !== null) {
            $params['cursor'] = $this->requiredText($cursor, 'cursor');
        }

        return $this->request($environment ?? $this->config->get('bybit.default_environment', 'testnet'), self::INSTRUMENTS_PATH, $params);
    }

    public function getKlines(
        string $symbol,
        string $interval,
        string $category = 'linear',
        ?int $start = null,
        ?int $end = null,
        int $limit = 200,
        ?string $environment = null,
    ): array {
        $this->validateCategory($category, ['spot', 'linear', 'inverse']);

        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Kline limit must be between 1 and 1000.');
        }

        if (($start !== null && $start < 0) || ($end !== null && $end < 0) || ($start !== null && $end !== null && $start > $end)) {
            throw new InvalidArgumentException('Kline start and end timestamps are invalid.');
        }

        $params = [
            'category' => $category,
            'symbol' => $this->requiredText($symbol, 'symbol'),
            'interval' => $this->normalizeInterval($interval),
            'limit' => $limit,
        ];

        if ($start !== null) {
            $params['start'] = $start;
        }

        if ($end !== null) {
            $params['end'] = $end;
        }

        return $this->request($environment ?? $this->config->get('bybit.default_environment', 'testnet'), self::KLINES_PATH, $params);
    }

    public function getWalletBalance(ExchangeAccount $account, string $accountType = 'UNIFIED', ?string $coin = null): array
    {
        if (! in_array($accountType, ['UNIFIED', 'CONTRACT', 'SPOT'], true)) {
            throw new InvalidArgumentException('Unsupported Bybit account type.');
        }

        $params = ['accountType' => $accountType];

        if ($coin !== null) {
            $params['coin'] = $this->requiredText($coin, 'coin');
        }

        return $this->requestForAccount($account, self::WALLET_BALANCE_PATH, $params);
    }

    public function getOpenPositions(
        ExchangeAccount $account,
        string $category = 'linear',
        ?string $symbol = null,
        ?string $settleCoin = null,
        int $limit = 200,
        ?string $cursor = null,
    ): array {
        $this->validateCategory($category, ['linear', 'inverse', 'option']);

        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('Position limit must be between 1 and 200.');
        }

        if ($symbol === null && $settleCoin === null && $category === 'linear') {
            $settleCoin = 'USDT';
        }

        if ($category === 'linear' && $symbol === null && $settleCoin === null) {
            throw new InvalidArgumentException('Linear position queries require a symbol or settle coin.');
        }

        $params = ['category' => $category];

        if ($symbol !== null) {
            $params['symbol'] = $this->requiredText($symbol, 'symbol');
        }

        if ($settleCoin !== null) {
            $params['settleCoin'] = $this->requiredText($settleCoin, 'settleCoin');
        }

        $params['limit'] = $limit;

        if ($cursor !== null) {
            $params['cursor'] = $this->requiredText($cursor, 'cursor');
        }

        return $this->requestForAccount($account, self::POSITIONS_PATH, $params);
    }

    /** @param array<string, string|int> $params
     * @return array<string, mixed>
     */
    private function requestForAccount(ExchangeAccount $account, string $path, array $params): array
    {
        $apiKey = $account->api_key;
        $apiSecret = $account->api_secret;

        if (! is_string($apiKey) || $apiKey === '' || ! is_string($apiSecret) || $apiSecret === '') {
            throw new BybitApiException('Exchange account credentials are missing.');
        }

        return $this->request($account->environment, $path, $params, $apiKey, $apiSecret);
    }

    /** @param array<string, string|int> $params
     * @return array<string, mixed>
     */
    private function request(string $environment, string $path, array $params, ?string $apiKey = null, ?string $apiSecret = null): array
    {
        $baseUrl = $this->baseUrl($environment);
        $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $url = $baseUrl.$path.($queryString === '' ? '' : '?'.$queryString);
        $maxRetries = max(0, (int) $this->config->get('bybit.max_retries', 2));
        $recvWindow = (string) $this->config->get('bybit.recv_window', 5000);

        for ($attempt = 0; ; $attempt++) {
            $headers = [];

            if ($apiKey !== null && $apiSecret !== null) {
                $timestamp = (string) Carbon::now('UTC')->getTimestampMs();
                $signaturePayload = $timestamp.$apiKey.$recvWindow.$queryString;
                $headers = [
                    'X-BAPI-API-KEY' => $apiKey,
                    'X-BAPI-TIMESTAMP' => $timestamp,
                    'X-BAPI-RECV-WINDOW' => $recvWindow,
                    'X-BAPI-SIGN' => hash_hmac('sha256', $signaturePayload, $apiSecret),
                ];
            }

            try {
                $response = $this->http
                    ->acceptJson()
                    ->timeout((float) $this->config->get('bybit.timeout', 10))
                    ->connectTimeout((float) $this->config->get('bybit.connect_timeout', 3))
                    ->withHeaders($headers)
                    ->get($url);
            } catch (ConnectionException) {
                if ($attempt < $maxRetries) {
                    $this->waitBeforeRetry();

                    continue;
                }

                throw new BybitApiException('Bybit request timed out or failed to connect.');
            }

            $body = $response->json();
            $retCode = is_array($body) && is_numeric($body['retCode'] ?? null) ? (int) $body['retCode'] : null;

            if ($this->isRateLimited($response, $retCode)) {
                if ($attempt < $maxRetries) {
                    $this->waitBeforeRetry($response);

                    continue;
                }

                throw new BybitRateLimitException($response->status(), $retCode);
            }

            if (! $response->successful()) {
                throw new BybitApiException(
                    'Bybit returned an HTTP error (status '.$response->status().').',
                    $response->status(),
                    $retCode,
                );
            }

            if (! is_array($body) || $retCode === null) {
                throw new BybitApiException('Bybit returned an invalid response.', $response->status());
            }

            if ($retCode !== 0) {
                $message = is_string($body['retMsg'] ?? null) ? $body['retMsg'] : 'Bybit rejected the request.';
                throw new BybitApiException('Bybit API error '.$retCode.': '.$message, $response->status(), $retCode);
            }

            if (! is_array($body['result'] ?? null)) {
                throw new BybitApiException('Bybit response did not contain a result object.', $response->status(), $retCode);
            }

            return $body['result'];
        }
    }

    private function isRateLimited(Response $response, ?int $retCode): bool
    {
        return $response->status() === 429 || in_array($retCode, [10006, 10018, 10429], true);
    }

    private function waitBeforeRetry(?Response $response = null): void
    {
        $delay = max(0, (int) $this->config->get('bybit.retry_delay_ms', 250));
        $retryAfter = $response?->header('Retry-After');

        if (is_numeric($retryAfter)) {
            $delay = max($delay, min((int) $retryAfter * 1000, 5000));
        }

        $resetAt = $response?->header('X-Bapi-Limit-Reset-Timestamp');

        if (is_numeric($resetAt)) {
            $untilReset = max(0, (int) ceil(((int) $resetAt - Carbon::now('UTC')->getTimestampMs()) / 1000));
            $delay = max($delay, min($untilReset, 5000));
        }

        if ($delay > 0) {
            usleep($delay * 1000);
        }
    }

    private function baseUrl(string $environment): string
    {
        $url = $this->config->get('bybit.base_urls.'.$environment);

        if (! is_string($url) || $url === '') {
            throw new InvalidArgumentException('Unsupported Bybit environment.');
        }

        return rtrim($url, '/');
    }

    private function normalizeInterval(string $interval): string
    {
        $normalized = [
            '15m' => '15',
            '1H' => '60',
            '4H' => '240',
            '1D' => 'D',
        ][$interval] ?? $interval;

        if (! in_array($normalized, ['1', '3', '5', '15', '30', '60', '120', '240', '360', '720', 'D', 'W', 'M'], true)) {
            throw new InvalidArgumentException('Unsupported Bybit kline interval.');
        }

        return $normalized;
    }

    /** @param list<string> $allowed */
    private function validateCategory(string $category, array $allowed): void
    {
        if (! in_array($category, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported Bybit product category.');
        }
    }

    private function requiredText(string $value, string $field): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException('The '.$field.' parameter cannot be empty.');
        }

        return $value;
    }
}
