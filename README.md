# Laravel Bybit Futures Bot

Laravel 12 application for read-only Bybit V5 market analysis. The project includes secure exchange account storage, the read-only V5 client, and a 4H altcoin scanner. It does not place or execute orders.

## Local setup

1. Install PHP 8.2+, Composer, MySQL, and Redis. The project uses Predis, so the PHP Redis extension is not required.
2. Install PHP dependencies and create a local environment file:

   ```powershell
   composer install
   Copy-Item .env.example .env
   php artisan key:generate
   ```

3. Create a MySQL database named `bybit_bot`, then set `DB_USERNAME` and `DB_PASSWORD` in `.env` for your local MySQL user. Leave all Bybit credential fields out of `.env`.
4. Ensure Redis is running on the configured host and port. Queues, cache, and distributed locks use Redis by default.
5. Apply the schema:

   ```powershell
   php artisan migrate
   ```

## Exchange account credentials

`ExchangeAccount` encrypts both `api_key` and `api_secret` with Laravel's encrypted Eloquent casts. The `APP_KEY` in `.env` is required to decrypt them and must be backed up securely. The model hides credential fields from array and JSON serialization. New accounts default to Bybit testnet and disabled.

Use Bybit API keys without withdrawal permission. No real credentials are included in this repository.

## Read-only Bybit V5 client

The injected `BybitReadOnlyClient` supports public tickers and candles, plus signed wallet-balance and open-position reads. Public market requests use the configured default environment (testnet); private reads use the environment saved on the exchange account. HMAC SHA256 signatures follow Bybit's V5 GET signing format. The client has bounded retries for connection failures and rate limits, and throws safe API exceptions without logging credentials. It exposes GET operations only. Candles accept Bybit intervals and `15m`, `1H`, `4H`, and `1D`; positions support Bybit's `limit` and `cursor` pagination parameters.

Configure the testnet or mainnet base URLs, receive window, timeouts, and retry limits with the `BYBIT_*` settings in `.env`. Bybit V5 signing and endpoint parameters are based on Bybit's [integration guide](https://bybit-exchange.github.io/docs/v5/guide), [market ticker](https://bybit-exchange.github.io/docs/v5/market/tickers), [kline](https://bybit-exchange.github.io/docs/v5/market/kline), [wallet balance](https://bybit-exchange.github.io/docs/v5/account/wallet-balance), and [position list](https://bybit-exchange.github.io/docs/v5/position) documentation.

## 4H altcoin scanner

The scanner paginates active linear instruments, keeps USDT-quoted/settled perpetuals, retrieves the ticker list once, then applies each strategy's BTC/ETH/stablecoin/symbol exclusions and optional 24-hour turnover threshold. The threshold is nullable and deliberately has no default. A missing threshold means no liquidity cutoff. Configure a strategy with:

```powershell
php artisan bybit:strategy:configure 1 --min-turnover=1000000 --volume-sma=20 --excluded=FOOUSDT,BARUSDT --activate
```

Use `--min-turnover=none` to remove the cutoff. By default BTC, ETH, and stablecoin base assets are excluded; `--include-btc`, `--include-eth`, or `--include-stablecoins` opts them back in. Use `--excluded=` to clear custom symbol exclusions.

The scanner requests 401 4H candles to retain 400 closed candles for indicator warmup. It discards unfinished or malformed candles and refuses to evaluate a required history window with timestamp gaps. EMA uses an SMA seed over its first period, then the standard `2 / (period + 1)` recursive formula. Heikin Ashi seeds its first open with `(regular open + regular close) / 2`. Volume SMA includes the latest closed candle and the previous period minus one candles.

Run the scanner on demand and start its worker/scheduler with:

```powershell
php artisan bybit:scan
php artisan queue:work redis --queue=bybit-market-data
php artisan schedule:work
```

The scheduler queues a scan five minutes after each 4-hour boundary. Redis-backed queue rate limits and locks reduce request bursts and overlapping/duplicate evaluations. `scan_runs` records counts and failure status. The Livewire results page is available at `/scanner`.

## Tests

Run the test suite with `php artisan test`. Tests use SQLite in memory and mocked Bybit responses; they do not contact Bybit.
