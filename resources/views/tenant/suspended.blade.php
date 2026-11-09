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
        <h1 class="text-xl font-bold text-slate-800 dark:text-white mb-2">{{ $tenant->status === 'closed' ? 'Lernplattform geschlossen' : 'Zugang derzeit nicht verfügbar' }}</h1>
        @php($contact = $tenant->branding?->support_email)
        @if ($tenant->status === 'closed')
            <p class="text-slate-500 dark:text-slate-400">Die Lernplattform von {{ $tenant->name }} ist geschlossen.</p>
        @else
            <p class="text-slate-500 dark:text-slate-400">Die Lernplattform von {{ $tenant->name }} ist vorübergehend nicht erreichbar.</p>
        @endif
        @if ($contact)
            <p class="mt-2 text-slate-500 dark:text-slate-400">Fragen? <a href="mailto:{{ $contact }}" class="underline">{{ $contact }}</a></p>
        @endif
        @auth
            <p class="mt-4 text-sm"><a href="{{ route('profile.data-rights') }}" class="underline text-slate-700 dark:text-slate-200">Eigene Daten herunterladen oder Konto löschen</a></p>
            {{-- Review-Runde 11 --}}
            <form method="POST" action="{{ route('logout') }}" class="mt-2">
                @csrf
                <button type="submit" class="text-sm underline text-slate-600 dark:text-slate-400">Abmelden</button>
            </form>
        @else
            {{-- Auskunft/Löschung (Art. 15/17/20 DSGVO) bleibt nach der Anmeldung möglich. --}}
            <p class="mt-4 text-sm"><a href="{{ route('login') }}" class="underline text-slate-700 dark:text-slate-200">Anmelden, um eigene Daten herunterzuladen oder das Konto zu löschen</a></p>
        @endauth
        <p class="mt-6 text-xs text-slate-500 dark:text-slate-400">
            <a href="{{ url('/impressum') }}" class="underline">Impressum</a> &middot; <a href="{{ url('/datenschutz') }}" class="underline">Datenschutz</a>
        </p>
    </div>
</body>
</html>
