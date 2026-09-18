<!DOCTYPE html>
<html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>Superadmin &middot; {{ config('app.name') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-slate-100 dark:bg-slate-950">
            <nav class="bg-slate-900 text-slate-200">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex justify-between h-16 items-center">
                        <div class="flex items-center gap-8">
                            <a href="{{ route('superadmin.dashboard') }}" class="flex items-center gap-2 shrink-0 font-bold text-white">
                                <x-icon name="shield-check" class="w-5 h-5 text-amber-400" />
                                Superadmin
                            </a>
                            <div class="hidden md:flex gap-1 text-sm">
                                <a href="{{ route('superadmin.dashboard') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.dashboard') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Dashboard</a>
                                <a href="{{ route('superadmin.tenants.index') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.tenants.*') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Bootsschulen</a>
                                <a href="{{ route('superadmin.users.index') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.users.*') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Nutzer</a>
                                <a href="{{ route('superadmin.courses.index') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.courses.*', 'superadmin.modules.*', 'superadmin.questions.*') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Kurse</a>
                                <a href="{{ route('superadmin.products.index') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.products.*') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Produkte</a>
                                <a href="{{ route('superadmin.coupons.index') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.coupons.*') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Coupons</a>
                                <a href="{{ route('superadmin.settings.edit') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.settings.*') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Website</a>
                            </div>
                        </div>
                        <div class="flex items-center gap-4 text-sm">
                            <span class="text-slate-400">{{ Auth::user()->name }}</span>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="text-slate-300 hover:text-white">Abmelden</button>
                            </form>
                        </div>
                    </div>
                </div>
            </nav>

            @isset($header)
                <header class="bg-white dark:bg-slate-900 shadow-sm">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                @if (session('status'))
                    <div class="mb-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 text-sm dark:bg-emerald-900/30 dark:border-emerald-800 dark:text-emerald-300 flex items-center justify-between gap-4 flex-wrap">
                        <span>{{ session('status') }}</span>
                        @if (session('generatedCodes'))
                            <a href="data:text/plain;charset=utf-8,{{ rawurlencode(implode("\n", session('generatedCodes'))) }}"
                               download="coupon-codes-{{ now()->format('Y-m-d_His') }}.txt"
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-xs font-medium hover:opacity-90 transition">
                                <x-icon name="download" class="w-3.5 h-3.5" /> Als TXT herunterladen
                            </a>
                        @endif
                    </div>
                @endif

                {{ $slot }}
            </main>
        </div>
    </body>
</html>
