@extends('layouts.app')

@section('content')
    <div class="mx-auto max-w-md rounded-2xl border border-slate-800 bg-slate-900 p-6 shadow-xl">
        <p class="text-sm font-medium uppercase tracking-[0.2em] text-cyan-400">Bybit · Read only</p>
        <h1 class="mt-2 text-2xl font-semibold text-white">Sign in</h1>
        <p class="mt-2 text-sm text-slate-400">Sign in with the account that owns the active scanner strategy to edit its settings.</p>

        <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label for="email" class="mb-2 block text-sm font-medium text-slate-200">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="min-h-11 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white focus:border-cyan-400 focus:outline-none focus:ring-1 focus:ring-cyan-400">
                @error('email')
                    <p role="alert" class="mt-2 text-sm text-rose-300">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="password" class="mb-2 block text-sm font-medium text-slate-200">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password" class="min-h-11 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white focus:border-cyan-400 focus:outline-none focus:ring-1 focus:ring-cyan-400">
                @error('password')
                    <p role="alert" class="mt-2 text-sm text-rose-300">{{ $message }}</p>
                @enderror
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-400">
                <input type="checkbox" name="remember" value="1" class="rounded border-slate-700 bg-slate-950 text-cyan-400 focus:ring-cyan-400">
                Remember me
            </label>
            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-cyan-400 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">Sign in</button>
        </form>
    </div>
@endsection
