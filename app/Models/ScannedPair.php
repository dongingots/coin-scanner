<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ScannedPair extends Model
{
    use HasFactory;

    protected $fillable = [
        'trading_strategy_id',
        'symbol',
        'base_coin',
        'quote_coin',
        'contract_type',
        'turnover_24h_usdt',
        'is_active',
        'last_scanned_at',
    ];

    protected function casts(): array
    {
        return [
            'turnover_24h_usdt' => 'decimal:8',
            'is_active' => 'boolean',
            'last_scanned_at' => 'datetime',
        ];
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'trading_strategy_id');
    }

    public function signals(): HasMany
    {
        return $this->hasMany(TradingSignal::class);
    }

    public function scanRunResults(): HasMany
    {
        return $this->hasMany(ScanRunPairResult::class);
    }

    public function latestSignal(): HasOne
    {
        return $this->hasOne(TradingSignal::class)->latestOfMany('candle_at');
    }
}
