<!DOCTYPE html>
<html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ isset($currentTenant) ? $currentTenant->name : config('app.name') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @if (isset($branding))
            <style>
                :root {
                    --brand-primary: {{ $branding->primary_color ?? '#005FD7' }};
                    --brand-secondary: {{ $branding->secondary_color ?? '#00A8A8' }};
                }
            </style>
        @endif
    </head>
    <body class="font-sans text-slate-900 antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-slate-100 dark:bg-slate-900">
            <div class="text-center">
                <div class="text-4xl mb-2">⚓</div>
                <div class="text-xl font-bold" style="color: var(--brand-primary, #005FD7)">{{ $currentTenant->name ?? config('app.name') }}</div>
                @if (isset($currentTenant))
                    <div class="text-sm text-slate-400">Dein Weg zum Bootsführerschein</div>
                @endif
            </div>

            <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white dark:bg-slate-800 shadow-md overflow-hidden sm:rounded-lg">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
