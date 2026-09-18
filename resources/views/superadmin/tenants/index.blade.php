<x-superadmin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Bootsschulen</h2>
            <a href="{{ route('superadmin.tenants.create') }}" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90 transition">
                + Neue Bootsschule
            </a>
        </div>
    </x-slot>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 dark:bg-slate-700 text-slate-500 dark:text-slate-300 text-left">
                <tr>
                    <th class="px-4 py-2">Name</th>
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
                        <td class="px-4 py-2 text-slate-500 font-mono text-xs">{{ $tenant->slug }}</td>
                        <td class="px-4 py-2">
                            <span class="inline-block px-2 py-0.5 rounded-full text-xs {{ $tenant->status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ $tenant->status }}</span>
                        </td>
                        <td class="px-4 py-2 text-slate-500">{{ $tenant->tenant_users_count }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-superadmin-layout>
