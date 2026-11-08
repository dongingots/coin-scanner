<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class TradingStrategy extends Model
{
    use HasFactory;

    protected $attributes = [
        'volume_sma_period' => 20,
        'rsi_maximum' => '65.00',
        'exclude_btc' => true,
        'exclude_eth' => true,
        'exclude_stablecoins' => true,
        'is_active' => false,
    ];

    protected $fillable = [
        'user_id',
        'name',
        'volume_sma_period',
        'rsi_maximum',
        'minimum_turnover_24h_usdt',
        'exclude_btc',
        'exclude_eth',
        'exclude_stablecoins',
        'excluded_symbols',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'minimum_turnover_24h_usdt' => 'decimal:8',
            'rsi_maximum' => 'decimal:2',
            'exclude_btc' => 'boolean',
            'exclude_eth' => 'boolean',
            'exclude_stablecoins' => 'boolean',
            'excluded_symbols' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scannedPairs(): HasMany
    {
        return $this->hasMany(ScannedPair::class);
    }

    public function tradingSignals(): HasManyThrough
    {
        return $this->hasManyThrough(TradingSignal::class, ScannedPair::class);
    }

    public function scanRuns(): HasMany
    {
        return $this->hasMany(ScanRun::class);
    }
}
