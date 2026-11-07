<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Protokoll</h2>
    </x-slot>

    <a href="{{ route('admin.dashboard') }}" class="text-sm text-slate-600 dark:text-slate-400 hover:underline">&larr; Zurück zum Admin-Bereich</a>

    <div class="mt-4 space-y-4">
        <form method="GET" action="{{ route('admin.audit.index') }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 grid grid-cols-2 md:grid-cols-5 gap-3 items-end">
            <div>
                <label for="action" class="block text-xs text-slate-600 dark:text-slate-400 mb-1">Aktion</label>
                <input id="action" name="action" type="text" value="{{ $filters['action'] }}" placeholder="z. B. participant oder coupon" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
            </div>
            <div>
                <label for="actor" class="block text-xs text-slate-600 dark:text-slate-400 mb-1">Person (E-Mail)</label>
                <input id="actor" name="actor" type="text" value="{{ $filters['actor'] }}" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
            </div>
            <div>
                <label for="from" class="block text-xs text-slate-600 dark:text-slate-400 mb-1">Von</label>
                <input id="from" name="from" type="date" value="{{ $filters['from'] }}" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
            </div>
            <div>
                <label for="to" class="block text-xs text-slate-600 dark:text-slate-400 mb-1">Bis</label>
                <input id="to" name="to" type="date" value="{{ $filters['to'] }}" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90">Filtern</button>
                <a href="{{ route('admin.audit.index') }}" class="px-3 py-2 rounded-lg bg-slate-100 dark:bg-slate-700 text-sm">Zurücksetzen</a>
            </div>
        </form>

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm divide-y divide-slate-100 dark:divide-slate-700">
            @forelse ($entries as $entry)
                <div class="px-4 py-2 flex flex-wrap gap-x-3 gap-y-0.5 text-sm">
                    <span class="w-32 shrink-0 text-xs text-slate-600 dark:text-slate-400 pt-0.5">{{ $entry->created_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</span>
                    <span class="flex-1 min-w-0 text-slate-700 dark:text-slate-200">{{ $entry->description() }}</span>
                </div>
            @empty
                <p class="px-4 py-6 text-sm text-slate-600 dark:text-slate-400">Keine Einträge gefunden.</p>
            @endforelse
        </div>
        {{ $entries->links() }}
    </div>
</x-app-layout>
