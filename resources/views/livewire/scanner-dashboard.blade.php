<section class="space-y-6" @if ($scanIsActive) wire:poll.4s @endif>
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-sm font-medium uppercase tracking-[0.2em] text-cyan-400">Bybit · Read only · {{ $marketEnvironment }} market data</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-white">Altcoin Bullish Scanner</h1>
            <p class="mt-2 text-sm text-slate-400">4H closed-candle evaluations · EMA 100 / 200 · Heikin Ashi · Volume SMA</p>
        </div>
        <div class="flex flex-wrap items-end gap-3">
            @if ($latestRun)
                <div class="rounded-xl border border-slate-800 bg-slate-900 px-4 py-3 text-sm">
                    <span class="text-slate-400">Latest scan</span>
                    <span class="ml-2 font-medium text-white">#{{ $latestRun->id }} · {{ str_replace('_', ' ', ucfirst($latestRun->status)) }}</span>
                    @if ($latestRun->finished_at)
                        <div class="mt-1 text-xs text-slate-500">Finished {{ $latestRun->finished_at->utc()->format('Y-m-d H:i:s') }} UTC</div>
                    @elseif ($latestRun->started_at)
                        <div class="mt-1 text-xs text-slate-500">{{ $latestRun->status === 'queued' ? 'Queued' : 'Started' }} {{ $latestRun->status === 'queued' && $latestRun->queued_at ? $latestRun->queued_at->utc()->format('Y-m-d H:i:s') : $latestRun->started_at->utc()->format('Y-m-d H:i:s') }} UTC</div>
                    @endif
                    @if ($latestRun->candle_at)
                        <div class="mt-1 text-xs text-slate-400">4H candle: {{ $latestRun->candle_at->utc()->format('Y-m-d H:i:s') }} UTC · {{ $latestRun->candle_at->copy()->setTimezone('Asia/Manila')->format('Y-m-d H:i:s') }} PHT</div>
                    @endif
                    @if ($latestRun->minimum_turnover_snapshot !== null)
                        <div class="mt-1 text-xs text-slate-400">Minimum turnover at scan: {{ number_format((float) $latestRun->minimum_turnover_snapshot, 8) }} USDT</div>
                    @endif
                    @if ($latestRun->maximum_coin_price_snapshot !== null)
                        <div class="mt-1 text-xs text-slate-400">Maximum coin price at scan: {{ number_format((float) $latestRun->maximum_coin_price_snapshot, 8) }} USDT</div>
                    @endif
                    @if ($latestRun->rsi_maximum_snapshot !== null)
                        <div class="mt-1 text-xs text-slate-400">RSI 14 maximum at scan: {{ number_format((float) $latestRun->rsi_maximum_snapshot, 2) }} · 4H</div>
                    @endif
                </div>
            @endif
            @if ($strategy && $scanIsActive)
                <button type="button" disabled aria-live="polite" class="inline-flex min-h-11 cursor-wait items-center justify-center gap-2 rounded-xl border border-cyan-800 bg-cyan-950/50 px-5 py-3 text-sm font-semibold text-cyan-200 opacity-80">
                    <span class="h-2 w-2 animate-pulse rounded-full bg-cyan-300"></span>
                    {{ $latestRun->status === 'queued' ? 'Scan queued' : 'Scanning…' }}
                </button>
            @elseif ($strategy && $latestRun?->status !== 'failed')
                <button type="button" wire:click="runScanNow" wire:loading.attr="disabled" wire:target="runScanNow" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-cyan-400 px-5 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-cyan-950/30 transition hover:bg-cyan-300 disabled:cursor-wait disabled:opacity-60">
                    <span wire:loading.remove wire:target="runScanNow">Run Scan Now</span>
                    <span wire:loading wire:target="runScanNow">Queueing scan…</span>
                </button>
            @endif
        </div>
    </header>

    @if ($scanNotice)
        <div role="{{ $scanNoticeType === 'error' ? 'alert' : 'status' }}" aria-live="polite" class="rounded-xl border px-4 py-3 text-sm {{ $scanNoticeType === 'error' ? 'border-rose-800 bg-rose-950/50 text-rose-200' : 'border-emerald-800 bg-emerald-950/40 text-emerald-200' }}">
            {{ $scanNotice }}
        </div>
    @endif

    @if ($settingsNotice)
        <div role="{{ $settingsNoticeType === 'error' ? 'alert' : 'status' }}" aria-live="polite" class="rounded-xl border px-4 py-3 text-sm {{ $settingsNoticeType === 'error' ? 'border-rose-800 bg-rose-950/50 text-rose-200' : 'border-emerald-800 bg-emerald-950/40 text-emerald-200' }}">
            {{ $settingsNotice }}
        </div>
    @endif

    @if (!$strategy)
        <div class="rounded-2xl border border-dashed border-slate-700 bg-slate-900/70 p-8 text-center">
            <h2 class="text-lg font-semibold text-white">No active strategy</h2>
            <p class="mx-auto mt-2 max-w-2xl text-sm text-slate-400">Create a TradingStrategy with a user, choose any optional turnover threshold and exclusions, activate it, then run <code class="text-cyan-300">php artisan bybit:scan</code>. The liquidity threshold is unset by default.</p>
        </div>
    @elseif ($scanIsActive)
        <section role="status" aria-live="polite" class="mx-auto w-full max-w-3xl overflow-hidden rounded-3xl border border-cyan-900/70 bg-slate-900/90 shadow-2xl shadow-cyan-950/20">
            <div class="p-6 text-center sm:p-10">
                <div class="relative mx-auto mb-6 flex h-24 w-24 items-center justify-center">
                    <span class="absolute inset-0 rounded-full border border-cyan-500/30"></span>
                    <span class="absolute inset-2 rounded-full border border-cyan-500/20"></span>
                    <span class="absolute inset-0 animate-ping rounded-full border border-cyan-400/20"></span>
                    <svg class="relative h-12 w-12 text-cyan-300 {{ $latestRun->status === 'running' ? 'animate-pulse' : '' }}" viewBox="0 0 48 48" fill="none" aria-hidden="true">
                        <circle cx="24" cy="24" r="17" stroke="currentColor" stroke-width="1.5" />
                        <circle cx="24" cy="24" r="3" fill="currentColor" />
                        <path d="M24 7v17l12 12M8 24h7m18 0h7M24 41v-7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                    </svg>
                </div>

                @if ($latestRun->status === 'queued')
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-amber-300">Queued</p>
                    <h2 class="mt-2 text-2xl font-semibold tracking-tight text-white sm:text-3xl">Waiting for a queue worker</h2>
                    <p class="mx-auto mt-3 max-w-xl text-sm leading-6 text-slate-400">Your scan is safely queued. The dashboard will update automatically when a worker starts processing it.</p>
                @else
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-cyan-300">Scan in progress</p>
                    <h2 class="mt-2 text-2xl font-semibold tracking-tight text-white sm:text-3xl">Scanning Bybit Markets</h2>
                    <p class="mx-auto mt-3 max-w-xl text-sm leading-6 text-slate-400">Market data is being evaluated. Please wait; this view refreshes automatically when the scan finishes.</p>
                @endif

                <div class="mx-auto mt-8 grid max-w-2xl gap-3 text-left sm:grid-cols-2">
                    <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-4">
                        <div class="text-xs uppercase tracking-wide text-slate-500">Scan run</div>
                        <div class="mt-1 font-semibold text-white">#{{ $latestRun->id }}</div>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-4">
                        <div class="text-xs uppercase tracking-wide text-slate-500">Elapsed</div>
                        <div class="mt-1 font-semibold text-white">{{ $scanElapsedLabel }} <span class="font-normal text-slate-400">{{ $latestRun->status === 'queued' ? 'queued' : 'scanning' }}</span></div>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-4 sm:col-span-2">
                        <div class="text-xs uppercase tracking-wide text-slate-500">4H candle being evaluated</div>
                        @if ($latestRun->candle_at)
                            <div class="mt-1 font-medium text-white">{{ $latestRun->candle_at->utc()->format('Y-m-d H:i:s') }} UTC <span class="text-slate-400">· {{ $latestRun->candle_at->copy()->setTimezone('Asia/Manila')->format('Y-m-d H:i:s') }} PHT</span></div>
                        @else
                            <div class="mt-1 text-slate-400">Awaiting the scan worker to confirm the candle.</div>
                        @endif
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-4 sm:col-span-2">
                        <div class="text-xs uppercase tracking-wide text-slate-500">Minimum 24H turnover</div>
                        @if ($latestRun->minimum_turnover_snapshot !== null)
                            <div class="mt-1 font-medium text-white">{{ number_format((float) $latestRun->minimum_turnover_snapshot, 8) }} USDT <span class="text-xs font-normal text-slate-500">scan snapshot</span></div>
                        @elseif ($strategy->minimum_turnover_24h_usdt !== null)
                            <div class="mt-1 font-medium text-white">{{ number_format((float) $strategy->minimum_turnover_24h_usdt, 8) }} USDT <span class="text-xs font-normal text-slate-500">current setting · run snapshot pending</span></div>
                        @else
                            <div class="mt-1 text-slate-400">No minimum configured <span class="text-xs text-slate-500">· run snapshot pending</span></div>
                        @endif
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-4 sm:col-span-2">
                        <div class="text-xs uppercase tracking-wide text-slate-500">RSI (14) maximum · 4H</div>
                        @if ($latestRun->rsi_maximum_snapshot !== null)
                            <div class="mt-1 font-medium text-white">{{ number_format((float) $latestRun->rsi_maximum_snapshot, 2) }} <span class="text-xs font-normal text-slate-500">scan snapshot</span></div>
                        @else
                            <div class="mt-1 font-medium text-white">{{ number_format((float) $strategy->rsi_maximum, 2) }} <span class="text-xs font-normal text-slate-500">current setting · run snapshot pending</span></div>
                        @endif
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-4 sm:col-span-2">
                        <div class="text-xs uppercase tracking-wide text-slate-500">Maximum coin price</div>
                        @if ($latestRun->maximum_coin_price_snapshot !== null)
                            <div class="mt-1 font-medium text-white">{{ number_format((float) $latestRun->maximum_coin_price_snapshot, 8) }} USDT <span class="text-xs font-normal text-slate-500">scan snapshot</span></div>
                        @else
                            <div class="mt-1 font-medium text-white">{{ number_format((float) $strategy->maximum_coin_price, 8) }} USDT <span class="text-xs font-normal text-slate-500">current setting · run snapshot pending</span></div>
                        @endif
                    </div>
                </div>
            </div>
        </section>
    @elseif ($latestRun?->status === 'failed')
        <section role="alert" aria-live="assertive" class="mx-auto w-full max-w-3xl rounded-3xl border border-rose-900/70 bg-rose-950/20 p-6 shadow-2xl shadow-rose-950/10 sm:p-10">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl border border-rose-800 bg-rose-950/70 text-rose-300">
                <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M12 8v5m0 3h.01M10.3 3.9 1.9 18.4A1.8 1.8 0 0 0 3.5 21h17a1.8 1.8 0 0 0 1.6-2.6L13.6 3.9a1.8 1.8 0 0 0-3.3 0Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </div>
            <p class="mt-5 text-center text-xs font-semibold uppercase tracking-[0.22em] text-rose-300">Scan failed · #{{ $latestRun->id }}</p>
            <h2 class="mt-2 text-center text-2xl font-semibold tracking-tight text-white">The market scan could not finish</h2>
            <p class="mx-auto mt-3 max-w-xl text-center text-sm leading-6 text-slate-400">{{ $latestRun->error_message ? str_replace(['_', ':'], [' ', ': '], $latestRun->error_message) : 'No failure details were recorded.' }}</p>
            @if ($latestRun->finished_at)
                <p class="mt-3 text-center text-xs text-slate-500">Failed {{ $latestRun->finished_at->utc()->format('Y-m-d H:i:s') }} UTC</p>
            @endif
            <div class="mt-7 flex justify-center">
                <button type="button" wire:click="runScanNow" wire:loading.attr="disabled" wire:target="runScanNow" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-cyan-400 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:cursor-wait disabled:opacity-60">
                    <span wire:loading.remove wire:target="runScanNow">Try a new scan</span>
                    <span wire:loading wire:target="runScanNow">Queueing scan…</span>
                </button>
            </div>
        </section>
    @else
        @if ($canManageStrategy)
            <section class="rounded-2xl border border-slate-800 bg-slate-900 p-5" aria-labelledby="scanner-settings-heading">
                <div class="mb-4">
                    <h2 id="scanner-settings-heading" class="text-lg font-semibold text-white">Strategy Settings</h2>
                    <p class="mt-1 text-sm text-slate-400">Configure eligibility limits for future scans.</p>
                </div>
                <form wire:submit="saveSettings" class="flex flex-wrap items-end gap-4">
                    <div class="min-w-64 flex-1">
                        <label for="minimum-turnover" class="mb-2 block text-sm font-medium text-slate-200">Minimum 24H Turnover (USDT)</label>
                        <div class="flex items-center gap-2">
                            <input id="minimum-turnover" type="number" min="0.00000001" step="0.00000001" inputmode="decimal" wire:model="minimumTurnover" class="min-h-11 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white focus:border-cyan-400 focus:outline-none focus:ring-1 focus:ring-cyan-400">
                            <span class="text-sm font-medium text-slate-400">USDT</span>
                        </div>
                        <div class="mt-2 text-xs text-slate-500">Current saved value: {{ $strategy->minimum_turnover_24h_usdt !== null ? number_format((float) $strategy->minimum_turnover_24h_usdt, 8).' USDT' : 'not set' }}</div>
                        @error('minimumTurnover')
                            <p role="alert" aria-live="polite" class="mt-2 text-sm text-rose-300">Enter a positive USDT amount with up to 8 decimal places.</p>
                        @enderror
                    </div>
                    <div class="min-w-52 flex-1">
                        <label for="maximum-coin-price" class="mb-2 block text-sm font-medium text-slate-200">Maximum Coin Price (USDT)</label>
                        <div class="flex items-center gap-2">
                            <input id="maximum-coin-price" type="number" min="0.00000001" step="0.00000001" inputmode="decimal" wire:model="maximumCoinPrice" class="min-h-11 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white focus:border-cyan-400 focus:outline-none focus:ring-1 focus:ring-cyan-400">
                            <span class="text-sm font-medium text-slate-400">USDT</span>
                        </div>
                        <div class="mt-2 text-xs text-slate-500">Current saved maximum: {{ number_format((float) $strategy->maximum_coin_price, 8) }} USDT · uses Bybit last price</div>
                        @error('maximumCoinPrice')
                            <p role="alert" aria-live="polite" class="mt-2 text-sm text-rose-300">Enter a positive USDT price with up to 8 decimal places.</p>
                        @enderror
                    </div>
                    <div class="min-w-52 flex-1">
                        <label for="rsi-maximum" class="mb-2 block text-sm font-medium text-slate-200">RSI (14) Maximum · 4H</label>
                        <div class="flex items-center gap-2">
                            <input id="rsi-maximum" type="number" min="0" max="100" step="0.01" inputmode="decimal" wire:model="rsiMaximum" class="min-h-11 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white focus:border-cyan-400 focus:outline-none focus:ring-1 focus:ring-cyan-400">
                            <span class="text-sm font-medium text-slate-400">RSI</span>
                        </div>
                        <div class="mt-2 text-xs text-slate-500">Current saved maximum: {{ number_format((float) $strategy->rsi_maximum, 2) }}</div>
                        @error('rsiMaximum')
                            <p role="alert" aria-live="polite" class="mt-2 text-sm text-rose-300">Enter a value from 0 to 100 with up to 2 decimal places.</p>
                        @enderror
                    </div>
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveSettings" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-cyan-500/50 bg-cyan-950/60 px-5 py-3 text-sm font-semibold text-cyan-200 transition hover:bg-cyan-900/70 disabled:cursor-wait disabled:opacity-60">
                        <span wire:loading.remove wire:target="saveSettings">Save Settings</span>
                        <span wire:loading wire:target="saveSettings">Saving…</span>
                    </button>
                </form>
            </section>
        @else
            <div class="rounded-xl border border-slate-800 bg-slate-900/70 px-4 py-3 text-sm text-slate-400">
                @if (auth()->check())
                    Sign in as the strategy owner to edit scanner settings.
                    <form method="POST" action="{{ route('logout') }}" class="ml-1 inline">
                        @csrf
                        <button type="submit" class="font-medium text-cyan-300 hover:text-cyan-200">Sign out</button>
                    </form>
                @else
                    Sign in as the strategy owner to edit scanner settings.
                    <a href="{{ route('login') }}" class="ml-1 font-medium text-cyan-300 hover:text-cyan-200">Sign in</a>
                @endif
            </div>
        @endif

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
            <div class="text-xs text-slate-500">Strategy: {{ $strategy->name }} · Max coin price: @if ($latestRun){{ $latestRun->maximum_coin_price_snapshot !== null ? number_format((float) $latestRun->maximum_coin_price_snapshot, 8).' USDT at scan' : 'snapshot unavailable' }}@else{{ number_format((float) $strategy->maximum_coin_price, 8).' USDT current setting' }}@endif · Volume SMA {{ $strategy->volume_sma_period }} · RSI (14) 4H ≤ {{ number_format((float) ($latestRun?->rsi_maximum_snapshot ?? $strategy->rsi_maximum), 2) }} · Minimum 24H turnover: {{ $strategy->minimum_turnover_24h_usdt ? number_format((float) $strategy->minimum_turnover_24h_usdt, 2).' USDT' : 'not set' }}</div>
        </div>

        @if ($resultsUnavailableMessage)
            <div role="status" class="rounded-xl border border-amber-800/70 bg-amber-950/30 px-4 py-3 text-sm text-amber-200">
                {{ $resultsUnavailableMessage }}
            </div>
        @else
            <div class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-800 text-sm">
                        <thead class="bg-slate-900/80 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="px-4 py-3">Symbol</th>
                                <th class="px-4 py-3">24H turnover snapshot</th>
                                <th class="px-4 py-3">Minimum used</th>
                                <th class="px-4 py-3">EMA 100 / 200</th>
                                <th class="px-4 py-3">Heikin Ashi</th>
                                <th class="px-4 py-3">Volume / SMA {{ $strategy->volume_sma_period }}</th>
                                <th class="px-4 py-3">RSI (14) · 4H</th>
                                <th class="px-4 py-3">RSI status</th>
                                <th class="px-4 py-3">Qualification</th>
                                <th class="px-4 py-3">Reason</th>
                                <th class="px-4 py-3">Evaluated (UTC)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/80">
                            @forelse ($pairs as $pairResult)
                                @php($signal = $pairResult->indicatorSignal)
                                @php($rsiStatusLabel = match ($pairResult->rsi_status) {
                                    'passed' => 'Passed',
                                    'rejected' => 'Rejected',
                                    'insufficient_history' => 'Insufficient history',
                                    'invalid_data' => 'Invalid data',
                                    default => 'Not evaluated',
                                })
                                <tr class="align-top hover:bg-slate-800/40">
                                    <td class="whitespace-nowrap px-4 py-4 font-semibold text-white">{{ $pairResult->scannedPair?->symbol ?? 'Pair unavailable' }}</td>
                                    <td class="whitespace-nowrap px-4 py-4 text-slate-300">{{ $pairResult->turnover_24h_snapshot !== null ? number_format((float) $pairResult->turnover_24h_snapshot, 8).' USDT' : 'Unavailable' }}</td>
                                    <td class="whitespace-nowrap px-4 py-4 text-slate-300">{{ $pairResult->minimum_turnover_snapshot !== null ? number_format((float) $pairResult->minimum_turnover_snapshot, 8).' USDT' : 'No minimum' }}</td>
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
                                    <td class="whitespace-nowrap px-4 py-4 text-slate-300">{{ $signal?->rsi_14 !== null ? number_format((float) $signal->rsi_14, 4) : '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-4">
                                        <span class="rounded-full px-2 py-1 text-xs {{ match ($pairResult->rsi_status) {
                                            'passed' => 'bg-emerald-950 text-emerald-300',
                                            'rejected', 'invalid_data' => 'bg-rose-950 text-rose-300',
                                            'insufficient_history' => 'bg-amber-950 text-amber-300',
                                            default => 'bg-slate-800 text-slate-400',
                                        } }}">{{ $rsiStatusLabel }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-4">
                                        @if ($pairResult->status === 'pending')
                                            <span class="text-amber-300">Pending</span>
                                        @elseif ($pairResult->status === 'failed')
                                            <span class="text-rose-300">Failed</span>
                                        @else
                                            <span class="rounded-full px-2 py-1 text-xs {{ $pairResult->is_qualified ? 'bg-emerald-950 text-emerald-300' : 'bg-slate-800 text-slate-300' }}">{{ $pairResult->is_qualified ? 'Qualified' : 'Not qualified' }}</span>
                                        @endif
                                    </td>
                                    <td class="max-w-xs px-4 py-4 text-xs text-slate-400">{{ $pairResult->evaluation_reason ?? 'awaiting_evaluation' }}</td>
                                    <td class="whitespace-nowrap px-4 py-4 text-xs text-slate-400">{{ $pairResult->evaluated_at?->utc()->format('Y-m-d H:i:s') ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="11" class="px-4 py-10 text-center text-slate-500">No pairs match this scan and filter yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($pairs)
                    <div class="border-t border-slate-800 px-4 py-3">{{ $pairs->links() }}</div>
                @endif
            </div>
        @endif
    @endif
</section>
