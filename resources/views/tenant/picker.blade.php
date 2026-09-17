<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Bootsschule wählen</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-100 dark:bg-slate-900 min-h-screen flex items-center justify-center p-6">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <div class="text-3xl mb-2">⚓</div>
            <h1 class="text-2xl font-bold text-slate-800 dark:text-white">White-Label Lernplattform</h1>
            <p class="text-slate-500 dark:text-slate-400">Bitte wählen Sie Ihre Bootsschule</p>
        </div>
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow p-4 space-y-2">
            @forelse ($tenants as $tenant)
                <a href="{{ url('/') }}?as_tenant={{ $tenant->slug }}"
                   class="flex items-center justify-between px-4 py-3 rounded-lg border border-slate-200 dark:border-slate-700 hover:border-blue-500 hover:bg-blue-50 dark:hover:bg-slate-700 transition">
                    <span class="font-medium text-slate-700 dark:text-slate-100">{{ $tenant->name }}</span>
                    <span class="text-xs text-slate-400">{{ $tenant->slug }}.{{ config('app.central_domain') }}</span>
                </a>
            @empty
                <p class="text-slate-500 text-sm p-4">Noch keine Bootsschule angelegt.</p>
            @endforelse
        </div>
        <p class="text-xs text-slate-400 text-center mt-6">
            Produktiv wird jede Bootsschule über ihre eigene Subdomain oder Domain erreicht.
        </p>
    </div>
</body>
</html>
