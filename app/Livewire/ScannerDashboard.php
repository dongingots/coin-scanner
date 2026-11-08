<?php

namespace App\Livewire;

use App\Models\ScanRun;
use App\Models\ScannedPair;
use App\Models\TradingStrategy;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class ScannerDashboard extends Component
{
    use WithPagination;

    public string $filter = 'all';

    public function setFilter(string $filter): void
    {
        if (in_array($filter, ['all', 'qualified', 'not_qualified'], true)) {
            $this->filter = $filter;
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $strategy = TradingStrategy::query()->where('is_active', true)->orderBy('id')->first();

        if ($strategy === null) {
            return view('livewire.scanner-dashboard', [
                'strategy' => null,
                'pairs' => null,
                'totalScanned' => 0,
                'qualifiedCount' => 0,
                'notQualifiedCount' => 0,
                'latestRun' => null,
            ]);
        }

        $baseQuery = ScannedPair::query()
            ->where('trading_strategy_id', $strategy->id)
            ->where('is_active', true);

        $totalScanned = (clone $baseQuery)->count();
        $qualifiedCount = (clone $baseQuery)->whereHas('latestSignal', fn ($query) => $query->where('is_qualified', true))->count();
        $notQualifiedCount = (clone $baseQuery)->whereHas('latestSignal', fn ($query) => $query->where('is_qualified', false))->count();

        $pairQuery = (clone $baseQuery)->with('latestSignal');

        if ($this->filter === 'qualified') {
            $pairQuery->whereHas('latestSignal', fn ($query) => $query->where('is_qualified', true));
        } elseif ($this->filter === 'not_qualified') {
            $pairQuery->whereHas('latestSignal', fn ($query) => $query->where('is_qualified', false));
        }

        return view('livewire.scanner-dashboard', [
            'strategy' => $strategy,
            'pairs' => $pairQuery->orderBy('symbol')->paginate(50),
            'totalScanned' => $totalScanned,
            'qualifiedCount' => $qualifiedCount,
            'notQualifiedCount' => $notQualifiedCount,
            'latestRun' => ScanRun::query()->where('trading_strategy_id', $strategy->id)->latest('started_at')->first(),
        ]);
    }
}
