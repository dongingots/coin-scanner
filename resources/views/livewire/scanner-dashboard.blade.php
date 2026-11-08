<section class="space-y-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-sm font-medium uppercase tracking-[0.2em] text-cyan-400">Bybit · Read only · Testnet market data</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-white">Altcoin Bullish Scanner</h1>
            <p class="mt-2 text-sm text-slate-400">4H closed-candle evaluations · EMA 100 / 200 · Heikin Ashi · Volume SMA</p>
        </div>
        @if ($latestRun)
            <div class="rounded-xl border border-slate-800 bg-slate-900 px-4 py-3 text-sm">
                <span class="text-slate-400">Latest scan</span>
                <span class="ml-2 font-medium text-white">{{ str_replace('_', ' ', ucfirst($latestRun->status)) }}</span>
                @if ($latestRun->finished_at)
                    <div class="mt-1 text-xs text-slate-500">{{ $latestRun->finished_at->utc()->format('Y-m-d H:i:s') }} UTC</div>
                @endif
            </div>
        @endif
    </header>

    @if (!$strategy)
        <div class="rounded-2xl border border-dashed border-slate-700 bg-slate-900/70 p-8 text-center">
            <h2 class="text-lg font-semibold text-white">No active strategy</h2>
            <p class="mx-auto mt-2 max-w-2xl text-sm text-slate-400">Create a TradingStrategy with a user, choose any optional turnover threshold and exclusions, activate it, then run <code class="text-cyan-300">php artisan bybit:scan</code>. The liquidity threshold is unset by default.</p>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-2xl border border-slate-800 bg-slate-900 p-5">
                <div class="text-sm text-slate-400">Altcoins scanned</div>
                <div class="mt-2 text-3xl font-semibold text-white">{{ number_format($totalScanned) }}</div>
            </div>
            <div class="rounded-2xl border border-emerald-900/70 bg-emerald-950/40 p-5">
                <div class="text-sm text-emerald-300">Qualified</div>
                <div class="mt-2 text-3xl font-semibold text-emerald-200">{{ number_format($qualifiedCount) }}</div>
            </div>
            <div class="rounded-2xl border border-rose-900/70 bg-rose-950/30 p-5">
                <div class="text-sm text-rose-300">Not qualified</div>
                <div class="mt-2 text-3xl font-semibold text-rose-200">{{ number_format($notQualifiedCount) }}</div>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex gap-2" role="group" aria-label="Filter scanned pairs">
                @foreach (['all' => 'All', 'qualified' => 'Qualified', 'not_qualified' => 'Not qualified'] as $value => $label)
                    <button type="button" wire:click="setFilter('{{ $value }}')" class="rounded-lg px-3 py-2 text-sm font-medium {{ $filter === $value ? 'bg-cyan-500 text-slate-950' : 'border border-slate-700 bg-slate-900 text-slate-300 hover:border-slate-500' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
            <div class="text-xs text-slate-500">Strategy: {{ $strategy->name }} · Volume SMA {{ $strategy->volume_sma_period }} · Minimum 24H turnover: {{ $strategy->minimum_turnover_24h_usdt ? number_format((float) $strategy->minimum_turnover_24h_usdt, 2).' USDT' : 'not set' }}</div>
        </div>

        <div class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-800 text-sm">
                    <thead class="bg-slate-900/80 text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr>
                            <th class="px-4 py-3">Symbol</th>
                            <th class="px-4 py-3">EMA 100 / 200</th>
                            <th class="px-4 py-3">Heikin Ashi</th>
                            <th class="px-4 py-3">Volume / SMA {{ $strategy->volume_sma_period }}</th>
                            <th class="px-4 py-3">Qualification</th>
                            <th class="px-4 py-3">Reason</th>
                            <th class="px-4 py-3">Last evaluated (UTC)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/80">
                        @forelse ($pairs as $pair)
                            @php($signal = $pair->latestSignal)
                            <tr class="align-top hover:bg-slate-800/40">
                                <td class="whitespace-nowrap px-4 py-4 font-semibold text-white">{{ $pair->symbol }}</td>
                                <td class="whitespace-nowrap px-4 py-4 text-slate-300">
                                    @if ($signal?->ema_100 !== null && $signal?->ema_200 !== null)
                                        <div>{{ number_format((float) $signal->ema_100, 8) }}</div>
                                        <div class="text-xs text-slate-500">{{ number_format((float) $signal->ema_200, 8) }}</div>
                                    @else
                                        <span class="text-slate-500">Unavailable</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-4">
                                    @if ($signal?->ha_bullish === null)
                                        <span class="text-slate-500">Unavailable</span>
                                    @else
                                        <span class="rounded-full px-2 py-1 text-xs {{ $signal->ha_bullish ? 'bg-emerald-950 text-emerald-300' : 'bg-rose-950 text-rose-300' }}">{{ $signal->ha_bullish ? 'Green' : 'Red' }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-slate-300">
                                    @if ($signal?->volume !== null && $signal?->volume_sma !== null)
                                        <div>{{ number_format((float) $signal->volume, 6) }}</div>
                                        <div class="text-xs text-slate-500">{{ number_format((float) $signal->volume_sma, 6) }}</div>
                                    @else
                                        <span class="text-slate-500">Unavailable</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-4">
                                    @if (!$signal)
                                        <span class="text-slate-500">Pending</span>
                                    @else
                                        <span class="rounded-full px-2 py-1 text-xs {{ $signal->is_qualified ? 'bg-emerald-950 text-emerald-300' : 'bg-slate-800 text-slate-300' }}">{{ $signal->is_qualified ? 'Qualified' : 'Not qualified' }}</span>
                                    @endif
                                </td>
                                <td class="max-w-xs px-4 py-4 text-xs text-slate-400">{{ $signal?->evaluation_reason ?? 'awaiting_evaluation' }}</td>
                                <td class="whitespace-nowrap px-4 py-4 text-xs text-slate-400">{{ $signal?->evaluated_at?->utc()->format('Y-m-d H:i:s') ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-10 text-center text-slate-500">No pairs match this filter yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($pairs)
                <div class="border-t border-slate-800 px-4 py-3">{{ $pairs->links() }}</div>
            @endif
        </div>
    @endif
</section>
