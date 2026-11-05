{{-- Impressum bzw. Datenschutzerklärung der Bootsschule (öffentlich), siehe
     App\Support\LegalPages und LegalPageController. --}}
<!DOCTYPE html>
<html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title }} – {{ isset($currentTenant) ? $currentTenant->name : \App\Models\PlatformSetting::siteName() }}</title>
        @if ((isset($branding) && $branding?->favicon_asset_id && $branding->faviconAsset))
            <link rel="icon" href="{{ $branding->faviconAsset->storage_path }}">
        @else
            <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
        @endif
        @if (isset($branding))
            <style>
                :root {
                    --brand-primary: {{ $branding->primaryColorOrDefault() }};
                    --brand-secondary: {{ $branding->secondaryColorOrDefault() }};
                    --brand-on-primary: {{ $branding->onPrimaryColor() }};
                }
            </style>
        @endif
        <style>
            .legal-text h2 { font-size: 1.125rem; font-weight: 600; margin: 1.75rem 0 0.5rem; }
            .legal-text p { margin: 0.5rem 0; line-height: 1.6; }
            .legal-text ul { list-style: disc; padding-left: 1.25rem; margin: 0.5rem 0; }
            .legal-text li { margin: 0.25rem 0; line-height: 1.6; }
            .legal-text a { text-decoration: underline; }
        </style>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-slate-900 dark:text-slate-100 antialiased bg-slate-50 dark:bg-slate-900">
        <div class="min-h-screen flex flex-col">
            <header class="bg-white dark:bg-slate-800 shadow-sm">
                <div class="max-w-3xl mx-auto px-4 sm:px-6 py-4 flex items-center justify-between gap-4">
                    <a href="{{ url('/') }}" class="flex items-center gap-2 font-semibold" style="color: var(--brand-primary, #005FD7)">
                        @if (isset($branding) && $branding->logo_asset_id && $branding->logoAsset)
                            <img src="{{ $branding->logoAsset->storage_path }}" alt="" class="h-8 w-auto">
                        @else
                            <x-icon name="anchor" class="w-6 h-6" />
                        @endif
                        {{ $currentTenant->name ?? \App\Models\PlatformSetting::siteName() }}
                    </a>
                    <a href="{{ url('/') }}" class="text-sm text-slate-600 dark:text-slate-300 underline">Zur Lernplattform</a>
                </div>
            </header>
            <main id="main-content" class="flex-1 w-full max-w-3xl mx-auto px-4 sm:px-6 py-8">
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 sm:p-8">
                    <h1 class="text-2xl font-bold mb-2">{{ $title }}</h1>
                    <div class="legal-text text-sm text-slate-700 dark:text-slate-200">{!! $content !!}</div>
                </div>
            </main>
            <x-site-footer />
        </div>
    </body>
</html>
