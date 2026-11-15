{{-- Ergebnis- und Fehlerseite der Web-Bestätigung (AppAccountDeletionController). --}}
<x-guest-layout>
    @push('head')
        <meta name="robots" content="noindex, nofollow">
        <meta name="referrer" content="no-referrer">
    @endpush

    <div role="{{ $success ? 'status' : 'alert' }}" class="text-center">
        <x-icon :name="$success ? 'check-circle' : 'x-circle'" class="w-12 h-12 mx-auto mb-3 {{ $success ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}" aria-hidden="true" />
        <h2 class="text-xl font-bold text-slate-800 dark:text-white mb-2">{{ $heading }}</h2>
        <p class="text-sm text-slate-700 dark:text-slate-300">{{ $message }}</p>
    </div>
</x-guest-layout>
