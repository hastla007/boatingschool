<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Willkommen, {{ Auth::user()->name }}!</h2>
    </x-slot>

    <p class="text-slate-500 mb-6">Hier sehen Sie die wichtigsten Informationen Ihrer Bootsschule.</p>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-8">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-4">
            <div class="text-2xl font-bold text-slate-800 dark:text-white">{{ $learnerCount }}</div>
            <div class="text-xs text-slate-400">Teilnehmer</div>
        </div>
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-4">
            <div class="text-2xl font-bold text-slate-800 dark:text-white">{{ $activeEntitlements }}</div>
            <div class="text-xs text-slate-400">Aktive Kurszugänge</div>
        </div>
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-4 col-span-2 sm:col-span-2">
            <a href="{{ route('admin.participants.index') }}" class="text-sm font-medium" style="color: var(--brand-primary, #005FD7)">Teilnehmer verwalten →</a>
            <br>
            <a href="{{ route('admin.branding.edit') }}" class="text-sm font-medium" style="color: var(--brand-primary, #005FD7)">Branding anpassen →</a>
        </div>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-4">
        <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Letzte Aktivitäten</h3>
        <div class="space-y-2 text-sm">
            @forelse ($recentActivity as $entry)
                <div class="flex justify-between border-b border-slate-100 dark:border-slate-700 pb-2">
                    <span class="text-slate-600 dark:text-slate-300">{{ $entry->action }}</span>
                    <span class="text-slate-400">{{ $entry->created_at->diffForHumans() }}</span>
                </div>
            @empty
                <p class="text-slate-500">Noch keine Aktivitäten protokolliert.</p>
            @endforelse
        </div>
    </div>
</x-app-layout>
