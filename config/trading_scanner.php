<?php

return [
    'environment' => env('BYBIT_SCANNER_ENVIRONMENT', 'testnet'),
    'timeframe' => '4H',
    'bybit_interval' => '240',
    'candle_interval_ms' => 14_400_000,
    'candle_history_limit' => (int) env('SCANNER_CANDLE_HISTORY_LIMIT', 400),
    'requests_per_second' => (int) env('SCANNER_REQUESTS_PER_SECOND', 4),
    'scan_lease_minutes' => (int) env('SCANNER_SCAN_LEASE_MINUTES', 360),
    'scan_orphan_grace_minutes' => (int) env('SCANNER_SCAN_ORPHAN_GRACE_MINUTES', 5),
    'stablecoin_base_coins' => [
        'DAI', 'FDUSD', 'PYUSD', 'TUSD', 'USDC', 'USDE', 'USDD', 'USDP', 'USTC',
    ],
];
