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
    </head>
    <body class="font-sans text-slate-900 antialiased">
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
                    <x-icon name="anchor" class="w-16 h-16 mx-auto text-white/90 mb-6" />
                    <h1 class="text-3xl font-bold text-white mb-3">{{ $currentTenant->name ?? config('app.name') }}</h1>
                    <p class="text-white/80 max-w-sm mx-auto">Dein Weg zum Bootsführerschein &mdash; SBF See, SBF Binnen, SRC und UBI in einer Lernplattform.</p>
                </div>
            </div>

            <div class="flex-1 flex flex-col min-h-screen px-6 py-12">
                <div class="flex-1 flex flex-col items-center justify-center">
                    <div class="w-full max-w-sm">
                        <div class="text-center mb-8 lg:hidden">
                            <x-icon name="anchor" class="w-10 h-10 mx-auto mb-2" style="color: var(--brand-primary, #005FD7)" />
                            <div class="text-xl font-bold" style="color: var(--brand-primary, #005FD7)">{{ $currentTenant->name ?? config('app.name') }}</div>
                            <div class="text-sm text-slate-400">Dein Weg zum Bootsführerschein</div>
                        </div>

                        <div class="bg-white dark:bg-slate-800 shadow-sm rounded-2xl px-6 py-8">
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
