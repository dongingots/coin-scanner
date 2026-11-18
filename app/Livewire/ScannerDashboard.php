<?php

namespace App\Livewire;

use App\Models\ScanRun;
use App\Models\ScanRunPairResult;
use App\Models\TradingStrategy;
use App\Services\Trading\ScanRequestService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class ScannerDashboard extends Component
{
    use WithPagination;

    public string $filter = 'all';

    public ?string $scanNotice = null;

    public string $scanNoticeType = 'success';

    public string $minimumTurnover = '';

    public string $maximumCoinPrice = '10.00000000';

    public string $rsiMaximum = '65.00';

    public ?string $settingsNotice = null;

    public string $settingsNoticeType = 'success';

    public function mount(): void
    {
        $strategy = $this->ownedActiveStrategy();
        $this->minimumTurnover = (string) ($strategy?->minimum_turnover_24h_usdt ?? '');
        $this->maximumCoinPrice = (string) ($strategy?->maximum_coin_price ?? '10.00000000');
        $this->rsiMaximum = (string) ($strategy?->rsi_maximum ?? '65.00');
    }

    public function setFilter(string $filter): void
    {
        if (in_array($filter, ['all', 'qualified', 'not_qualified'], true)) {
            $this->filter = $filter;
            $this->resetPage();
        }
    }

    public function runScanNow(ScanRequestService $scans): void
    {
        try {
            $request = $scans->queueScan();
        } catch (Throwable) {
            $this->scanNoticeType = 'error';
            $this->scanNotice = 'The scan request could not be processed. Check the database and queue connection.';

            return;
        }

        if ($request['status'] === 'queued') {
            $candleAt = isset($request['candle_at'])
                ? Carbon::parse($request['candle_at'])
                : null;
            $phtTime = $candleAt?->copy()->setTimezone('Asia/Manila')->format('Y-m-d H:i:s').' PHT';
            $utcTime = $candleAt?->copy()->utc()->format('Y-m-d H:i:s').' UTC';

            $this->scanNoticeType = 'success';
            $this->scanNotice = 'Market scan queued'.($utcTime ? " for the 4H candle opened at {$utcTime} ({$phtTime})." : '.');

            return;
        }

        $this->scanNoticeType = 'error';
        $this->scanNotice = match ($request['status']) {
            'already_running' => 'A market scan is already queued or running. Wait for it to finish before requesting another scan.',
            'no_active_strategy' => 'No active trading strategy is available to scan.',
            default => 'The market scan could not be queued. Check the Redis queue connection and try again.',
        };
    }

    public function saveSettings(): void
    {
        $userId = Auth::id();
        abort_unless($userId !== null, 403);

        $strategy = TradingStrategy::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
        abort_unless($strategy !== null, 403);

        $this->settingsNotice = null;

        try {
            $validated = $this->validate([
                'minimumTurnover' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,24}(?:\.\d{1,8})?$/'],
                'maximumCoinPrice' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,24}(?:\.\d{1,8})?$/'],
                'rsiMaximum' => ['required', 'numeric', 'min:0', 'max:100', 'regex:/^\d{1,3}(?:\.\d{1,2})?$/'],
            ]);
        } catch (ValidationException $exception) {
            $this->settingsNoticeType = 'error';
            $this->settingsNotice = 'Please correct the scanner settings and try again.';

            throw $exception;
        }

        DB::transaction(function () use ($strategy, $userId, $validated): void {
            $lockedStrategy = TradingStrategy::query()
                ->whereKey($strategy->id)
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedStrategy->update([
                'minimum_turnover_24h_usdt' => $validated['minimumTurnover'],
                'maximum_coin_price' => $validated['maximumCoinPrice'],
                'rsi_maximum' => $validated['rsiMaximum'],
            ]);
        });

        $freshStrategy = $strategy->fresh();
        $this->minimumTurnover = (string) $freshStrategy->minimum_turnover_24h_usdt;
        $this->maximumCoinPrice = (string) $freshStrategy->maximum_coin_price;
        $this->rsiMaximum = (string) $freshStrategy->rsi_maximum;
        $this->settingsNoticeType = 'success';
        $this->settingsNotice = 'Scanner settings saved. New scans will use the updated thresholds.';
        $this->resetValidation();
    }

    public function render(): View
    {
        if (
            $this->scanNoticeType === 'error'
            && str_starts_with((string) $this->scanNotice, 'A market scan is already queued or running.')
            && ! ScanRun::query()->whereIn('status', ['queued', 'running'])->exists()
        ) {
            $this->scanNotice = null;
        }

        $strategyQuery = TradingStrategy::query()->where('is_active', true)->orderBy('id');

        if (Auth::id() !== null) {
            $strategyQuery->where('user_id', Auth::id());
        }

        $strategy = $strategyQuery->first();
        $canManageStrategy = $strategy !== null && (int) Auth::id() === (int) $strategy->user_id;

        if ($strategy === null) {
            return view('livewire.scanner-dashboard', [
                'strategy' => null,
                'pairs' => null,
                'totalScanned' => 0,
                'qualifiedCount' => 0,
                'notQualifiedCount' => 0,
                'latestRun' => null,
                'resultsAvailable' => false,
                'resultsUnavailableMessage' => null,
                'scanIsActive' => false,
                'scanElapsedLabel' => null,
                'marketEnvironment' => strtoupper((string) config('trading_scanner.environment', 'testnet')),
                'canManageStrategy' => false,
            ]);
        }

        $latestRun = ScanRun::query()
            ->where('trading_strategy_id', $strategy->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
        $scanIsActive = $latestRun !== null && in_array($latestRun->status, ['queued', 'running'], true);
        $showResults = $latestRun !== null && ! $scanIsActive && $latestRun->status !== 'failed';
        $resultsAvailable = $showResults && $latestRun->pairResults()->exists();
        $resultsUnavailableMessage = $latestRun === null
            ? 'No scan results are available yet.'
            : null;

        if ($showResults && ! $resultsAvailable) {
            $resultsUnavailableMessage = in_array($latestRun->status, ['queued', 'running'], true)
                ? 'Run-scoped results are not available yet for this scan.'
                : 'Historical results are unavailable for this scan because it predates run-scoped results.';
        }

        $totalScanned = $showResults ? ($latestRun->pair_count ?? 0) : 0;
        $qualifiedCount = $showResults ? ($latestRun->qualified_count ?? 0) : 0;
        $notQualifiedCount = $showResults ? ($latestRun->not_qualified_count ?? 0) : 0;
        $scanStartedAt = $latestRun === null
            ? null
            : ($latestRun->status === 'queued'
                ? ($latestRun->queued_at ?? $latestRun->started_at ?? $latestRun->created_at)
                : ($latestRun->started_at ?? $latestRun->queued_at ?? $latestRun->created_at));

        $pairs = null;

        if ($showResults) {
            $pairQuery = ScanRunPairResult::query()
                ->leftJoin('scanned_pairs', 'scan_run_pair_results.scanned_pair_id', '=', 'scanned_pairs.id')
                ->where('scan_run_pair_results.scan_run_id', $latestRun->id)
                ->select('scan_run_pair_results.*')
                ->with(['scannedPair', 'indicatorSignal']);

            if ($this->filter === 'qualified') {
                $pairQuery->where('status', ScanRunPairResult::STATUS_EVALUATED)->where('is_qualified', true);
            } elseif ($this->filter === 'not_qualified') {
                $pairQuery->where('status', ScanRunPairResult::STATUS_EVALUATED)->where('is_qualified', false);
            }

            $pairs = $pairQuery
                ->orderBy('scanned_pairs.symbol')
                ->orderBy('scan_run_pair_results.id')
                ->paginate(50);
        }

        return view('livewire.scanner-dashboard', [
            'strategy' => $strategy,
            'pairs' => $pairs,
            'totalScanned' => $totalScanned,
            'qualifiedCount' => $qualifiedCount,
            'notQualifiedCount' => $notQualifiedCount,
            'latestRun' => $latestRun,
            'resultsAvailable' => $resultsAvailable,
            'resultsUnavailableMessage' => $resultsUnavailableMessage,
            'scanIsActive' => $scanIsActive,
            'scanElapsedLabel' => $scanStartedAt === null ? null : $this->formatElapsed($scanStartedAt->diffInSeconds(now())),
            'marketEnvironment' => strtoupper((string) config('trading_scanner.environment', 'testnet')),
            'canManageStrategy' => $canManageStrategy,
        ]);
    }

    private function formatElapsed(int|float $seconds): string
    {
        $remaining = max(0, (int) $seconds);
        $units = [
            'd' => 86400,
            'h' => 3600,
            'm' => 60,
            's' => 1,
        ];
        $parts = [];

        foreach ($units as $label => $length) {
            $value = intdiv($remaining, $length);

            if ($value > 0 || ($label === 's' && $parts === [])) {
                $parts[] = $value.$label;
                $remaining %= $length;
            }
        }

        return implode(' ', $parts);
    }

    private function ownedActiveStrategy(): ?TradingStrategy
    {
        $userId = Auth::id();

        if ($userId === null) {
            return null;
        }

        return TradingStrategy::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }
}
