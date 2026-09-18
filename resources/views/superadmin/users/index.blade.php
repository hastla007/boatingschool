<x-superadmin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Nutzer</h2>
            <a href="{{ route('superadmin.users.create') }}" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90 transition">
                + Neuer Nutzer
            </a>
        </div>
    </x-slot>

    <form method="GET" class="mb-4 max-w-sm">
        <input type="search" name="q" value="{{ $search }}" placeholder="Name oder E-Mail suchen&hellip;" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
    </form>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 dark:bg-slate-700 text-slate-500 dark:text-slate-300 text-left">
                <tr>
                    <th class="px-4 py-2">Name</th>
                    <th class="px-4 py-2">E-Mail</th>
                    <th class="px-4 py-2">Bootsschulen</th>
                    <th class="px-4 py-2">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    <tr class="border-t border-slate-100 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700/50">
                        <td class="px-4 py-2">
                            <a href="{{ route('superadmin.users.show', $user) }}" class="font-medium text-slate-700 dark:text-slate-200 hover:underline">{{ $user->name }}</a>
                            @if ($user->is_superadmin)
                                <span class="ml-1 inline-block px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 text-[10px] font-semibold uppercase">Superadmin</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-slate-500">{{ $user->email }}</td>
                        <td class="px-4 py-2 text-slate-500">
                            @forelse ($user->tenantMemberships as $membership)
                                <span class="inline-block px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-xs mb-1">{{ $membership->tenant->name }} ({{ $membership->role }})</span>
                            @empty
                                <span class="text-slate-300">keine</span>
                            @endforelse
                        </td>
                        <td class="px-4 py-2 text-slate-500">{{ $user->status }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $users->links() }}</div>
</x-superadmin-layout>
