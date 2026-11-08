<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class ScanRunPairResult extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_EVALUATED = 'evaluated';

    public const STATUS_FAILED = 'failed';

    public const RSI_NOT_EVALUATED = 'not_evaluated';

    public const RSI_PASSED = 'passed';

    public const RSI_REJECTED = 'rejected';

    public const RSI_INSUFFICIENT_HISTORY = 'insufficient_history';

    public const RSI_INVALID_DATA = 'invalid_data';

    protected $fillable = [
        'scan_run_id',
        'scanned_pair_id',
        'indicator_signal_id',
        'turnover_24h_snapshot',
        'minimum_turnover_snapshot',
        'rsi_status',
        'status',
        'is_qualified',
        'evaluation_reason',
        'evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'turnover_24h_snapshot' => 'decimal:8',
            'minimum_turnover_snapshot' => 'decimal:8',
            'is_qualified' => 'boolean',
            'evaluated_at' => 'datetime',
        ];
    }

    public function scanRun(): BelongsTo
    {
        return $this->belongsTo(ScanRun::class);
    }

    public function scannedPair(): BelongsTo
    {
        return $this->belongsTo(ScannedPair::class);
    }

    public function indicatorSignal(): BelongsTo
    {
        return $this->belongsTo(TradingSignal::class, 'indicator_signal_id');
    }

    public function complete(bool $qualified, string $reason, ?int $indicatorSignalId = null, ?string $rsiStatus = null): bool
    {
        return DB::transaction(function () use ($qualified, $reason, $indicatorSignalId, $rsiStatus): bool {
            $run = ScanRun::query()->whereKey($this->scan_run_id)->lockForUpdate()->firstOrFail();
            $result = self::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();

            if ($result->status !== self::STATUS_PENDING) {
                return false;
            }

            $attributes = [
                'indicator_signal_id' => $indicatorSignalId,
                'status' => self::STATUS_EVALUATED,
                'is_qualified' => $qualified,
                'evaluation_reason' => $reason,
                'evaluated_at' => now(),
            ];

            if ($rsiStatus !== null) {
                $attributes['rsi_status'] = $rsiStatus;
            }

            $result->update($attributes);

            $run->recordResult($qualified);

            return true;
        });
    }

    public function fail(string $reason): bool
    {
        return DB::transaction(function () use ($reason): bool {
            $run = ScanRun::query()->whereKey($this->scan_run_id)->lockForUpdate()->firstOrFail();
            $result = self::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();

            if ($result->status !== self::STATUS_PENDING) {
                return false;
            }

            $result->update([
                'status' => self::STATUS_FAILED,
                'is_qualified' => null,
                'evaluation_reason' => $reason,
                'evaluated_at' => now(),
            ]);

            $run->recordFailure($reason);

            return true;
        });
    }
}
