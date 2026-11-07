{{-- „Meine Daten“: Export und Kontolöschung, auch wenn der Zugang zur Bootsschule gesperrt ist (Art. 15/17/20 DSGVO). --}}
<x-guest-layout>
    <h1 class="text-lg font-semibold text-slate-800 dark:text-white">Meine Daten bei {{ $tenant->name }}</h1>

    @if (session('status'))
        <p class="mt-3 text-sm text-slate-700 dark:text-slate-200 bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 rounded-lg px-3 py-2">{{ session('status') }}</p>
    @endif

    <div class="mt-5 space-y-6">
        <section>
            <h2 class="font-medium text-slate-800 dark:text-slate-100">Daten herunterladen</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Alle bei dieser Bootsschule über dich gespeicherten Daten als Datei (Art. 15/20 DSGVO).</p>
            <a href="{{ route('profile.export') }}" class="mt-3 inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium text-white hover:opacity-90" style="background-color: var(--brand-primary, #005FD7)">Meine Daten exportieren</a>
        </section>

        <div class="border-t border-slate-200 dark:border-slate-700 pt-6">
            @include('profile.partials.delete-user-form')
        </div>

        <form method="POST" action="{{ route('logout') }}" class="border-t border-slate-200 dark:border-slate-700 pt-4">
            @csrf
            <button type="submit" class="text-sm text-slate-600 dark:text-slate-400 underline hover:no-underline">Abmelden</button>
        </form>
    </div>
</x-guest-layout>
