<?php

namespace App\Services\Trading;

use App\Contracts\BybitReadOnlyClient;
use App\Exceptions\BybitApiException;
use App\Models\TradingStrategy;

class MarketUniverseService
{
    public function __construct(private readonly BybitReadOnlyClient $bybit) {}

    /** @return array{instruments:list<array<string,mixed>>,tickers:array<string,array<string,mixed>>} */
    public function fetchSnapshot(): array
    {
        $environment = (string) config('trading_scanner.environment', 'testnet');
        $instruments = [];
        $cursor = null;
        $seenCursors = [];

        do {
            $page = $this->bybit->getLinearInstruments($cursor, 1000, $environment);
            $list = $page['list'] ?? null;

            if (! is_array($list)) {
                throw new BybitApiException('Bybit instrument response did not contain a list.');
            }

            foreach ($list as $instrument) {
                if (is_array($instrument)) {
                    $instruments[] = $instrument;
                }
            }

            $nextCursor = $page['nextPageCursor'] ?? null;
            $cursor = is_string($nextCursor) && $nextCursor !== '' ? $nextCursor : null;

            if ($cursor !== null && isset($seenCursors[$cursor])) {
                throw new BybitApiException('Bybit instrument pagination repeated a cursor.');
            }

            if ($cursor !== null) {
                $seenCursors[$cursor] = true;
            }
        } while ($cursor !== null);

        $tickerResult = $this->bybit->getTickers('linear', null, $environment);
        $tickerList = $tickerResult['list'] ?? null;

        if (! is_array($tickerList)) {
            throw new BybitApiException('Bybit ticker response did not contain a list.');
        }

        $tickers = [];

        foreach ($tickerList as $ticker) {
            if (is_array($ticker) && is_string($ticker['symbol'] ?? null)) {
                $tickers[strtoupper($ticker['symbol'])] = $ticker;
            }
        }

        return ['instruments' => $instruments, 'tickers' => $tickers];
    }

    /** @param array{instruments:list<array<string,mixed>>,tickers:array<string,array<string,mixed>>} $snapshot
     *  @return list<array{symbol:string,base_coin:string,quote_coin:string,contract_type:string,turnover:?string,can_evaluate:bool,reason:?string}>
     */
    public function candidatesFor(TradingStrategy $strategy, array $snapshot): array
    {
        $candidates = [];
        $excludedSymbols = array_map('strtoupper', $strategy->excluded_symbols ?? []);
        $stablecoins = array_map('strtoupper', config('trading_scanner.stablecoin_base_coins', []));
        $minimumTurnover = $strategy->minimum_turnover_24h_usdt;

        foreach ($snapshot['instruments'] as $instrument) {
            $symbol = strtoupper((string) ($instrument['symbol'] ?? ''));
            $baseCoin = strtoupper((string) ($instrument['baseCoin'] ?? ''));
            $quoteCoin = strtoupper((string) ($instrument['quoteCoin'] ?? ''));
            $settleCoin = strtoupper((string) ($instrument['settleCoin'] ?? ''));

            if ($symbol === '' || ($instrument['status'] ?? null) !== 'Trading') {
                continue;
            }

            if (($instrument['contractType'] ?? null) !== 'LinearPerpetual'
                || $quoteCoin !== 'USDT'
                || $settleCoin !== 'USDT'
                || ! str_ends_with($symbol, 'USDT')) {
                continue;
            }

            $baseCoin = $baseCoin !== '' ? $baseCoin : substr($symbol, 0, -4);

            if (($strategy->exclude_btc && $baseCoin === 'BTC')
                || ($strategy->exclude_eth && $baseCoin === 'ETH')
                || ($strategy->exclude_stablecoins && in_array($baseCoin, $stablecoins, true))
                || in_array($symbol, $excludedSymbols, true)) {
                continue;
            }

            $ticker = $snapshot['tickers'][$symbol] ?? null;
            $turnover = $ticker['turnover24h'] ?? null;
            $turnoverIsValid = is_numeric($turnover) && is_finite((float) $turnover) && (float) $turnover >= 0;
            $turnoverValue = $turnoverIsValid ? (string) $turnover : null;
            $reason = null;

            if (! $turnoverIsValid) {
                $reason = 'ticker_turnover_unavailable';
            } elseif ($minimumTurnover !== null && (float) $turnoverValue < (float) $minimumTurnover) {
                $reason = 'turnover_below_configured_minimum';
            }

            $candidates[] = [
                'symbol' => $symbol,
                'base_coin' => $baseCoin,
                'quote_coin' => $quoteCoin,
                'contract_type' => 'LinearPerpetual',
                'turnover' => $turnoverValue,
                'can_evaluate' => $reason === null,
                'reason' => $reason,
            ];
        }

        return $candidates;
    }
}
