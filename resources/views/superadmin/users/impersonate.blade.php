<x-superadmin-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Support-Ansicht</h1>
    </x-slot>

    @push('head')
        {{-- Weiterleitung per Link statt Redirect nach dem Formular: die CSP (form-action 'self') ließe einen Redirect auf eine andere Domain (Subdomain, eigene Domain) nicht zu. --}}
        <meta http-equiv="refresh" content="0;url={{ $url }}">
    @endpush
    <div class="max-w-xl bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 space-y-3 text-sm">
        <p class="text-slate-700 dark:text-slate-200">Du wirst zur Ansicht als <strong>{{ $user->name }}</strong> bei <strong>{{ $tenant->name }}</strong> weitergeleitet. Die Ansicht ist nur lesend, endet spätestens nach {{ \App\Support\Impersonation::MAX_MINUTES }} Minuten und wird protokolliert.</p>
        <a href="{{ $url }}" class="inline-block px-4 py-2 rounded-lg bg-slate-900 text-white font-medium hover:opacity-90">Support-Ansicht öffnen</a>
        <p class="text-xs text-slate-600 dark:text-slate-400">Der Link gilt {{ \App\Support\Impersonation::TOKEN_TTL_SECONDS }} Sekunden und nur einmal.</p>
    </div>
</x-superadmin-layout>
