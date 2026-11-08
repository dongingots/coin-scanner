<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'trading_strategy_id',
        'status',
        'instrument_count',
        'pair_count',
        'evaluated_count',
        'qualified_count',
        'not_qualified_count',
        'failed_count',
        'started_at',
        'finished_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'trading_strategy_id');
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
