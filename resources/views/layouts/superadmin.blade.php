<!DOCTYPE html>
<html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>Superadmin &middot; {{ \App\Models\PlatformSetting::siteName() }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        {{-- Bug-Review Oberfläche U7: kein Skip-Link -- ein Tastatur-/
             Screenreader-Nutzer musste bisher jeden Menüpunkt der
             Superadmin-Navigation einzeln durchtaben, um zum eigentlichen
             Seiteninhalt zu gelangen. --}}
        <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:top-2 focus:left-2 focus:px-4 focus:py-2 focus:rounded-lg focus:bg-white focus:text-slate-900 focus:shadow-lg">Zum Hauptinhalt springen</a>
        <div class="min-h-screen bg-slate-100 dark:bg-slate-950">
            {{--
                Bug-Review (Hoch): die gesamte Navigation steckte bisher in
                einem reinen "hidden md:flex" (Zeile unten) -- unterhalb von
                768px Breite verschwand sie komplett, ohne jeden Ersatz
                (kein Hamburger-Menü wie im Lerner-Layout, siehe
                layouts/navigation.blade.php). Ein Superadmin auf dem Handy
                konnte dadurch keine der 11 Sektionen erreichen außer der,
                auf der er gerade zufällig stand. Der Hamburger-Umschalter
                unten übernimmt dasselbe, bereits bewährte Alpine-Muster.
            --}}
            <nav x-data="{ open: false }" class="bg-slate-900 text-slate-200">
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
                                <a href="{{ route('superadmin.courses.index') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.courses.*', 'superadmin.modules.*', 'superadmin.questions.*', 'superadmin.video-lessons.*') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Kurse</a>
                                <a href="{{ route('superadmin.products.index') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.products.*') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Produkte</a>
                                <a href="{{ route('superadmin.tips.index') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.tips.*') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Tipps & Tricks</a>
                                <a href="{{ route('superadmin.coupons.index') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.coupons.*') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Coupons</a>
                                <a href="{{ route('superadmin.addon-templates.index') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.addon-templates.*') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Addons</a>
                                <a href="{{ route('superadmin.praxis-form-templates.index') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.praxis-form-templates.*') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Praxisformulare</a>
                                <a href="{{ route('superadmin.pruefungsausschuesse.index') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.pruefungsausschuesse.*') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Prüfungsausschüsse</a>
                                <a href="{{ route('superadmin.settings.edit') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.settings.*') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Website</a>
                            </div>
                        </div>
                        <div class="hidden md:flex items-center gap-4 text-sm">
                            <span class="text-slate-600 dark:text-slate-400">{{ Auth::user()->name }}</span>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="text-slate-300 hover:text-white">Abmelden</button>
                            </form>
                        </div>
                        <div class="-me-2 flex items-center md:hidden">
                            {{-- Bug-Review Oberfläche U7: siehe layouts/navigation.blade.php -- derselbe
                                 Hamburger ohne erkennbaren Namen, ohne aria-expanded/aria-controls und
                                 ohne sichtbaren Fokusring. --}}
                            <button @click="open = ! open" :aria-expanded="open" aria-controls="superadmin-mobile-menu" aria-label="Menü öffnen/schließen" class="inline-flex items-center justify-center p-2 rounded-md text-slate-300 hover:text-white hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-400 focus:ring-offset-2 focus:ring-offset-slate-900">
                                <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                    <path :class="{'hidden': open, 'inline-flex': ! open }" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                                    <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div id="superadmin-mobile-menu" :class="{'block': open, 'hidden': ! open}" class="hidden md:hidden border-t border-slate-800">
                    <div class="px-2 pt-2 pb-3 space-y-1">
                        <a href="{{ route('superadmin.dashboard') }}" class="block px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.dashboard') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Dashboard</a>
                        <a href="{{ route('superadmin.tenants.index') }}" class="block px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.tenants.*') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Bootsschulen</a>
                        <a href="{{ route('superadmin.users.index') }}" class="block px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.users.*') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Nutzer</a>
                        <a href="{{ route('superadmin.courses.index') }}" class="block px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.courses.*', 'superadmin.modules.*', 'superadmin.questions.*', 'superadmin.video-lessons.*') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Kurse</a>
                        <a href="{{ route('superadmin.products.index') }}" class="block px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.products.*') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Produkte</a>
                        <a href="{{ route('superadmin.tips.index') }}" class="block px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.tips.*') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Tipps & Tricks</a>
                        <a href="{{ route('superadmin.coupons.index') }}" class="block px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.coupons.*') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Coupons</a>
                        <a href="{{ route('superadmin.addon-templates.index') }}" class="block px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.addon-templates.*') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Addons</a>
                        <a href="{{ route('superadmin.praxis-form-templates.index') }}" class="block px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.praxis-form-templates.*') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Praxisformulare</a>
                        <a href="{{ route('superadmin.pruefungsausschuesse.index') }}" class="block px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.pruefungsausschuesse.*') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Prüfungsausschüsse</a>
                        <a href="{{ route('superadmin.settings.edit') }}" class="block px-3 py-2 rounded-lg {{ request()->routeIs('superadmin.settings.*') ? 'bg-slate-800 text-white' : 'hover:bg-slate-800/60' }}">Website</a>
                    </div>
                    <div class="pt-3 pb-3 border-t border-slate-800 px-5">
                        <div class="text-slate-600 dark:text-slate-400 text-sm mb-2">{{ Auth::user()->name }}</div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="text-slate-300 hover:text-white text-sm">Abmelden</button>
                        </form>
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

            <main id="main-content" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
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

            <x-site-footer />
        </div>
    </body>
</html>
