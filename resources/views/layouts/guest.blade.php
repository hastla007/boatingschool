<!DOCTYPE html>
<html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ isset($currentTenant) ? $currentTenant->name : \App\Models\PlatformSetting::siteName() }}</title>

        {{-- Bug-Review Oberfläche U4: siehe layouts/app.blade.php -- ohne
             eigenes <link rel="icon"> fiel der Browser auf die leere
             /favicon.ico zurück. --}}
        @if ((isset($branding) && $branding?->favicon_asset_id && $branding->faviconAsset))
            <link rel="icon" href="{{ $branding->faviconAsset->storage_path }}">
        @else
            <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
        @endif

        {{-- Bug-Review (Fonts von fonts.bunny.net ohne Einwilligung): siehe
             layouts/app.blade.php -- Figtree wird jetzt selbst gehostet über
             resources/css/app.css (@fontsource), kein Drittanbieter-Request
             mehr. --}}

        {{-- Bug-Review Oberfläche U4: siehe layouts/app.blade.php --}}
        @if (isset($branding))
            <style>
                :root {
                    --brand-primary: {{ $branding->primaryColorOrDefault() }};
                    --brand-secondary: {{ $branding->secondaryColorOrDefault() }};
                }
            </style>
        @endif

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-slate-900 antialiased">
        {{-- Bug-Review Oberfläche U7: siehe layouts/app.blade.php -- fehlte
             hier ebenfalls. --}}
        <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:top-2 focus:left-2 focus:px-4 focus:py-2 focus:rounded-lg focus:bg-white focus:text-slate-900 focus:shadow-lg">Zum Hauptinhalt springen</a>
        <div class="min-h-screen flex bg-slate-50 dark:bg-slate-900">
            <div class="hidden lg:flex lg:w-1/2 relative overflow-hidden items-center justify-center"
                 style="background: linear-gradient(160deg, var(--brand-primary, #005FD7), color-mix(in srgb, var(--brand-primary, #005FD7) 55%, #001233));">
                <svg class="absolute inset-x-0 bottom-0 w-full text-white/10" viewBox="0 0 1440 220" fill="currentColor" preserveAspectRatio="none">
                    <path d="M0,128L80,144C160,160,320,192,480,181.3C640,171,800,117,960,112C1120,107,1280,149,1360,170.7L1440,192L1440,320L0,320Z"></path>
                </svg>
                <svg class="absolute inset-x-0 bottom-10 w-full text-white/10" viewBox="0 0 1440 220" fill="currentColor" preserveAspectRatio="none">
                    <path d="M0,192L80,181.3C160,171,320,149,480,154.7C640,160,800,192,960,197.3C1120,203,1280,181,1360,170.7L1440,160L1440,320L0,320Z"></path>
                </svg>
                <div class="relative text-center px-12">
                    {{-- Bug-Review Oberfläche U4: das Logo erschien bisher
                         nur auf der Kursseite -- auf der Login-Seite stand
                         immer nur das generische Anker-Icon, selbst wenn die
                         Bootsschule bereits ein eigenes Logo hochgeladen
                         hatte. --}}
                    @if (isset($branding) && $branding->logo_asset_id && $branding->logoAsset)
                        <img src="{{ $branding->logoAsset->storage_path }}" alt="{{ $currentTenant->name }} Logo" class="max-w-[8rem] max-h-[8rem] w-auto h-auto object-contain mx-auto mb-6">
                    @else
                        <x-icon name="anchor" class="w-16 h-16 mx-auto text-white/90 mb-6" />
                    @endif
                    <h1 class="text-3xl font-bold text-white mb-3">{{ $currentTenant->name ?? config('app.name') }}</h1>
                    <p class="text-white/80 max-w-sm mx-auto">Dein Weg zum Bootsführerschein &mdash; SBF See, SBF Binnen, SRC und UBI in einer Lernplattform.</p>
                </div>
            </div>

            <div class="flex-1 flex flex-col min-h-screen px-6 py-12">
                <div class="flex-1 flex flex-col items-center justify-center">
                    <div class="w-full max-w-sm">
                        <div class="text-center mb-8 lg:hidden">
                            @if (isset($branding) && $branding->logo_asset_id && $branding->logoAsset)
                                <img src="{{ $branding->logoAsset->storage_path }}" alt="{{ $currentTenant->name }} Logo" class="max-w-[4rem] max-h-[4rem] w-auto h-auto object-contain mx-auto mb-2">
                            @else
                                <x-icon name="anchor" class="w-10 h-10 mx-auto mb-2" style="color: var(--brand-primary, #005FD7)" />
                            @endif
                            <div class="text-xl font-bold" style="color: var(--brand-primary, #005FD7)">{{ $currentTenant->name ?? config('app.name') }}</div>
                            <div class="text-sm text-slate-600 dark:text-slate-400">Dein Weg zum Bootsführerschein</div>
                        </div>

                        <div id="main-content" class="bg-white dark:bg-slate-800 shadow-sm rounded-2xl px-6 py-8">
                            {{ $slot }}
                        </div>
                    </div>
                </div>

                @unless (request()->routeIs('login'))
                    <x-site-footer />
                @endunless
            </div>
        </div>
    </body>
</html>
