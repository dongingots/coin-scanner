<?php

return [
    /*
    | The testnet default prevents a newly configured account from accidentally
    | targeting Bybit mainnet. Live trading remains disabled until a later phase
    | adds and enforces an explicit live-trading gate.
    */
    'default_environment' => env('BYBIT_DEFAULT_ENVIRONMENT', 'testnet'),
    'live_trading_enabled' => (bool) env('BYBIT_LIVE_TRADING_ENABLED', false),
    'base_urls' => [
        'testnet' => env('BYBIT_TESTNET_URL', 'https://api-testnet.bybit.com'),
        'mainnet' => env('BYBIT_MAINNET_URL', 'https://api.bybit.com'),
    ],
    'recv_window' => (int) env('BYBIT_RECV_WINDOW', 5000),
    'timeout' => (float) env('BYBIT_TIMEOUT', 10),
    'connect_timeout' => (float) env('BYBIT_CONNECT_TIMEOUT', 3),
    'max_retries' => (int) env('BYBIT_MAX_RETRIES', 2),
    'retry_delay_ms' => (int) env('BYBIT_RETRY_DELAY_MS', 250),
];
