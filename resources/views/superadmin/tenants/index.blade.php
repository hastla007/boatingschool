<x-superadmin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Bootsschulen</h1>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('superadmin.announcements.index') }}" class="px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200 text-sm font-medium hover:bg-slate-50 dark:hover:bg-slate-700 transition">
                    Mitteilung an alle Schulen
                </a>
                <a href="{{ route('superadmin.tenants.create') }}" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90 transition">
                    + Neue Bootsschule
                </a>
            </div>
        </div>
    </x-slot>

    <form method="GET" class="mb-4 flex flex-wrap gap-2 items-end" role="search" aria-label="Bootsschulen suchen">
        <div class="w-full sm:w-auto sm:min-w-[18rem]">
            <label for="tenant_search" class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Name, Slug oder Domain</label>
            <input type="search" id="tenant_search" name="q" value="{{ $search }}" placeholder="z. B. segelschule" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
        </div>
        <div>
            <label for="tenant_status" class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Status</label>
            <select id="tenant_status" name="status" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                <option value="">Alle</option>
                @foreach (\App\Support\StatusLabels::tenantStatusOptions() as $value => $label)
                    <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="px-3 py-2 rounded-lg bg-slate-900 text-white text-sm">Suchen</button>
        @if ($search !== '' || $status !== '')
            <a href="{{ route('superadmin.tenants.index') }}" class="px-3 py-2 text-sm text-slate-600 dark:text-slate-300 hover:underline">Filter zurücksetzen</a>
        @endif
    </form>

    <p class="mb-2 text-sm text-slate-600 dark:text-slate-300" data-tenant-count>
        @if ($tenants->total() === 0)
            Keine Bootsschulen gefunden.
        @else
            {{ $tenants->total() }} {{ $tenants->total() === 1 ? 'Bootsschule' : 'Bootsschulen' }}{!! $tenants->lastPage() > 1 ? ', '.$tenants->firstItem().'&ndash;'.$tenants->lastItem().' angezeigt' : '' !!}, sortiert nach Name.
        @endif
    </p>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
        <div tabindex="0" role="region" aria-label="Tabelle: Bootsschulen (seitlich scrollbar)" class="overflow-x-auto">
        <table class="w-full text-sm">
            <caption class="sr-only">Bootsschulen, sortiert nach Name</caption>
            <thead class="bg-slate-50 dark:bg-slate-700 text-slate-500 dark:text-slate-300 text-left">
                <tr>
                    <th class="px-4 py-2" aria-sort="ascending">Name</th>
                    <th class="px-4 py-2">Slug</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Nutzer</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tenants as $tenant)
                    <tr class="border-t border-slate-100 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700/50">
                        <td class="px-4 py-2">
                            <a href="{{ route('superadmin.tenants.show', $tenant) }}" class="font-medium text-slate-700 dark:text-slate-200 hover:underline">{{ $tenant->name }}</a>
                        </td>
                        <td class="px-4 py-2 text-slate-500 dark:text-slate-400 font-mono text-xs">{{ $tenant->slug }}</td>
                        <td class="px-4 py-2">
                            <span class="inline-block px-2 py-0.5 rounded-full text-xs {{ $tenant->status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500 dark:text-slate-400' }}">{{ \App\Support\StatusLabels::tenantStatus($tenant->status) }}</span>
                        </td>
                        <td class="px-4 py-2 text-slate-500 dark:text-slate-400">{{ $tenant->tenant_users_count }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>
    <div class="mt-4">{{ $tenants->links() }}</div>
</x-superadmin-layout>
