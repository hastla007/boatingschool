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

                {{-- Bug-Review T4: Admin\EntitlementController::store()/
                     update() liefern bei fehlender Code-Verfügbarkeit
                     (Freischalten/Verlängern/Widerruf aufheben) bisher
                     back()->withErrors(['course_id' => ...]) bzw.
                     ['action' => ...] -- dieses Layout rendert aber nur
                     session('status') global, $errors sonst nirgends auf
                     den betroffenen Seiten (admin/tabs/participants.blade.php,
                     admin/participants/show.blade.php kennen nur Felder wie
                     street/postal_code/birthdate). Der Admin sah nach einem
                     Klick auf "Freischalten"/"Verlängern"/"Widerruf
                     aufheben" ohne freien Code dadurch gar keine Rückmeldung
                     -- der Request schlug lediglich unsichtbar fehl.

                     Bug-Review (Fehlermeldungen erscheinen doppelt): diese
                     globale Box zeigte danach $errors->all() komplett an,
                     auch Meldungen, die eine Seite bereits direkt neben
                     ihrem Feld anzeigt (z. B. <x-input-error> bei
                     Adresse/Coupon-Zuweisung) -- jede so eingeleitete
                     Meldung erschien dadurch zweimal. $slot ist an dieser
                     Stelle bereits vollständig gerendert (Blade führt den
                     Inhalt zwischen <x-app-layout>...</x-app-layout> vor
                     dieser Vorlage aus), ShownFormErrors kennt die dabei
                     bereits angezeigten Meldungen also schon. --}}
                @php($globalErrors = collect($errors->all())->reject(fn ($message) => \App\Support\ShownFormErrors::wasShown($message)))
                @if ($globalErrors->isNotEmpty())
                    <div class="mb-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 text-sm dark:bg-rose-900/30 dark:border-rose-800 dark:text-rose-300">
                        <ul class="space-y-1">
                            @foreach ($globalErrors as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{ $slot }}
            </main>

            <x-site-footer />
        </div>

        <x-whatsapp-support-button />
    </body>
</html>
