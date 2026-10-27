<!DOCTYPE html>
<html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ isset($currentTenant) ? $currentTenant->name.' – Lernplattform' : \App\Models\PlatformSetting::siteName() }}</title>

        {{-- Bug-Review Oberfläche U4: ohne ein eigenes <link rel="icon">
             fiel der Browser auf /favicon.ico zurück -- die Datei existierte,
             war aber 0 Byte groß, das Tab-Icon blieb dadurch für jede
             Bootsschule leer. Das von der Bootsschule hochgeladene Favicon
             hat Vorrang, sonst greift das Plattform-Standard-SVG. --}}
        @if ((isset($branding) && $branding?->favicon_asset_id && $branding->faviconAsset))
            <link rel="icon" href="{{ $branding->faviconAsset->storage_path }}">
        @else
            <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
        @endif

        {{-- Bug-Review (Fonts von fonts.bunny.net ohne Einwilligung): Figtree/
             Caveat kamen bisher per <link> direkt von fonts.bunny.net -- ein
             Drittanbieter-Request schon beim reinen Seitenaufruf, vor jeder
             Einwilligung. Die Schriften werden jetzt selbst gehostet über
             resources/css/app.css (@fontsource), siehe @vite unten. --}}

        {{-- Bug-Review Oberfläche U4: primary_color/secondary_color wurden
             bisher nirgends als CSS-Variable definiert -- jedes
             var(--brand-primary, #005FD7) im Markup griff dadurch immer nur
             auf den hartkodierten Fallback zurück. Mit der Definition hier
             greifen alle bestehenden var(...)-Stellen automatisch, ohne dass
             jede einzelne Stelle geändert werden muss. --}}
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
    <body class="font-sans antialiased">
        {{-- Bug-Review Oberfläche U7: kein Skip-Link -- ein Tastatur-/
             Screenreader-Nutzer musste bisher die gesamte Navigation
             (inkl. Kurse-Untermenü) durchtaben, um zum eigentlichen
             Seiteninhalt zu gelangen. --}}
        <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:top-2 focus:left-2 focus:px-4 focus:py-2 focus:rounded-lg focus:bg-white focus:text-slate-900 focus:shadow-lg">Zum Hauptinhalt springen</a>
        <div class="min-h-screen bg-slate-50 dark:bg-slate-900">
            @include('layouts.navigation')

            @isset($header)
                <header class="bg-white dark:bg-slate-800 shadow-sm">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <main id="main-content" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                @if (session('status'))
                    <div class="mb-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 text-sm dark:bg-emerald-900/30 dark:border-emerald-800 dark:text-emerald-300">
                        {{ session('status') }}
                    </div>
                @endif

                {{ $slot }}
            </main>

            <x-site-footer />
        </div>

        <x-whatsapp-support-button />
    </body>
</html>
