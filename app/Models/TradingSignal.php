<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradingSignal extends Model
{
    use HasFactory;

    protected $fillable = [
        'scanned_pair_id',
        'timeframe',
        'candle_at',
        'ema_100',
        'ema_200',
        'ha_open',
        'ha_close',
        'volume',
        'volume_sma',
        'ema_bullish',
        'ha_bullish',
        'volume_confirmed',
        'is_qualified',
        'evaluation_reason',
        'evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'candle_at' => 'datetime',
            'ema_100' => 'decimal:12',
            'ema_200' => 'decimal:12',
            'ha_open' => 'decimal:12',
            'ha_close' => 'decimal:12',
            'volume' => 'decimal:12',
            'volume_sma' => 'decimal:12',
            'ema_bullish' => 'boolean',
            'ha_bullish' => 'boolean',
            'volume_confirmed' => 'boolean',
            'is_qualified' => 'boolean',
            'evaluated_at' => 'datetime',
        ];
    }

    public function scannedPair(): BelongsTo
    {
        return $this->belongsTo(ScannedPair::class);
    }
}
