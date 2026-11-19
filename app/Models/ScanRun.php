<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScanRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'trading_strategy_id',
        'status',
        'queued_at',
        'instrument_count',
        'pair_count',
        'evaluated_count',
        'qualified_count',
        'not_qualified_count',
        'failed_count',
        'started_at',
        'candle_at',
        'minimum_turnover_snapshot',
        'maximum_coin_price_snapshot',
        'ema_trend_filter_enabled_snapshot',
        'rsi_maximum_snapshot',
        'finished_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'candle_at' => 'datetime',
            'minimum_turnover_snapshot' => 'decimal:8',
            'maximum_coin_price_snapshot' => 'decimal:8',
            'ema_trend_filter_enabled_snapshot' => 'boolean',
            'rsi_maximum_snapshot' => 'decimal:2',
            'finished_at' => 'datetime',
        ];
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'trading_strategy_id');
    }

    public function pairResults(): HasMany
    {
        return $this->hasMany(ScanRunPairResult::class);
    }

    public function recordResult(bool $qualified): void
    {
        $this->increment('evaluated_count');
        $this->increment($qualified ? 'qualified_count' : 'not_qualified_count');
        $this->finishIfSettled();
    }

    public function recordFailure(string $reason): void
    {
        $this->increment('failed_count');
        $this->update(['error_message' => mb_substr($reason, 0, 2000)]);
        $this->finishIfSettled();
    }

    public function finishIfSettled(): void
    {
        $this->refresh();

        if ($this->status !== 'running' || $this->evaluated_count + $this->failed_count < $this->pair_count) {
            return;
        }

        $this->update([
            'status' => $this->failed_count > 0 ? 'completed_with_errors' : 'completed',
            'finished_at' => now(),
        ]);
    }
}
