<x-guest-layout>
    @push('head')
        {{-- Weiterleitung per Seite statt Redirect nach dem Formular (CSP form-action 'self', zentrale Domain kann eine andere sein). --}}
        <meta http-equiv="refresh" content="1;url={{ $url }}">
    @endpush
    <div class="text-center space-y-3">
        <h1 class="text-lg font-semibold text-slate-800 dark:text-slate-100">Support-Ansicht beendet</h1>
        <p class="text-sm text-slate-600 dark:text-slate-300">Du wirst zum Superadmin-Bereich weitergeleitet.</p>
        <a href="{{ $url }}" class="inline-block px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90">Zum Superadmin-Bereich</a>
    </div>
</x-guest-layout>
