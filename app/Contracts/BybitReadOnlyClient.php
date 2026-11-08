<?php

namespace App\Contracts;

use App\Models\ExchangeAccount;

interface BybitReadOnlyClient
{
    /** @return array<string, mixed> */
    public function getTickers(string $category = 'linear', ?string $symbol = null, ?string $environment = null): array;

    /** @return array<string, mixed> */
    public function getLinearInstruments(?string $cursor = null, int $limit = 1000, ?string $environment = null): array;

    /** @return array<string, mixed> */
    public function getKlines(
        string $symbol,
        string $interval,
        string $category = 'linear',
        ?int $start = null,
        ?int $end = null,
        int $limit = 200,
        ?string $environment = null,
    ): array;

    /** @return array<string, mixed> */
    public function getWalletBalance(ExchangeAccount $account, string $accountType = 'UNIFIED', ?string $coin = null): array;

    /** @return array<string, mixed> */
    public function getOpenPositions(
        ExchangeAccount $account,
        string $category = 'linear',
        ?string $symbol = null,
        ?string $settleCoin = null,
        int $limit = 200,
        ?string $cursor = null,
    ): array;
}
