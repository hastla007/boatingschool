<x-guest-layout>
    {{-- Einmalige Altersbestätigung vor dem Annehmen einer Einladung (InvitationAcceptController). --}}
    <h2 class="text-xl font-bold text-slate-800 dark:text-white mb-2">Einladung annehmen</h2>
    <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
        {{ $tenantName ? 'Du wurdest zu '.$tenantName.' eingeladen.' : 'Du wurdest zu einer Bootsschule eingeladen.' }}
        Bitte bestätige vorher noch einmalig:
    </p>

    <form method="POST" action="{{ $action }}" class="space-y-4">
        @csrf
        @include('auth.partials.age-confirmation')

        <button type="submit" class="w-full px-4 py-2.5 rounded-lg font-semibold text-white transition hover:opacity-90" style="background-color: var(--brand-primary, #005FD7)">
            Einladung annehmen
        </button>
    </form>
</x-guest-layout>
