<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Zugang gesperrt</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-100 dark:bg-slate-900 min-h-screen flex items-center justify-center p-6">
    <div class="text-center max-w-md">
        <div class="w-14 h-14 rounded-full bg-rose-50 dark:bg-rose-900/30 flex items-center justify-center mx-auto mb-4">
            <x-icon name="lock-closed" class="w-7 h-7 text-rose-400" />
        </div>
        <h1 class="text-xl font-bold text-slate-800 dark:text-white mb-2">Zugang derzeit nicht verfügbar</h1>
        <p class="text-slate-500 dark:text-slate-400">Der Zugang von {{ $tenant->name }} ist momentan gesperrt. Bitte wenden Sie sich an den Support.</p>
    </div>
</body>
</html>
