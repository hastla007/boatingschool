<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Wartungsarbeiten &middot; {{ $siteName }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-100 dark:bg-slate-900 min-h-screen flex items-center justify-center p-6">
    <div class="text-center max-w-md">
        <div class="w-14 h-14 rounded-full bg-amber-50 dark:bg-amber-900/30 flex items-center justify-center mx-auto mb-4">
            <x-icon name="bolt" class="w-7 h-7 text-amber-400" />
        </div>
        <h1 class="text-xl font-bold text-slate-800 dark:text-white mb-2">Wartungsarbeiten</h1>
        <p class="text-slate-500 dark:text-slate-400">{{ $message ?: 'Die Plattform ist gerade kurz nicht erreichbar. Bitte versuchen Sie es in Kürze erneut.' }}</p>
        @if (! empty($supportEmail))
            <p class="text-sm text-slate-600 dark:text-slate-400 dark:text-slate-500 mt-4">
                Fragen? <a href="mailto:{{ $supportEmail }}" class="underline">{{ $supportEmail }}</a>
            </p>
        @endif
    </div>
</body>
</html>
