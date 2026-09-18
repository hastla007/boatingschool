<x-superadmin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-xs text-slate-400"><a href="{{ route('superadmin.tenants.index') }}" class="hover:underline">Bootsschulen</a></div>
                <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">{{ $tenant->name }}</h2>
            </div>
            <span class="inline-block px-2 py-0.5 rounded-full text-xs {{ $tenant->status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ $tenant->status }}</span>
        </div>
    </x-slot>

    <div class="grid lg:grid-cols-3 gap-4 mb-6">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 lg:col-span-1">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Stammdaten</h3>
            <form method="POST" action="{{ route('superadmin.tenants.update', $tenant) }}" class="space-y-3">
                @csrf
                @method('PATCH')
                <div>
                    <x-input-label for="name" value="Name" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $tenant->name)" required />
                </div>
                <div>
                    <x-input-label for="status" value="Status" />
                    <select id="status" name="status" class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                        @foreach (['trial', 'active', 'suspended', 'closed'] as $option)
                            <option value="{{ $option }}" @selected(old('status', $tenant->status) === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90 transition">Speichern</button>
            </form>

            <div class="mt-5 pt-5 border-t border-slate-100 dark:border-slate-700 text-sm space-y-1.5">
                <div class="flex justify-between"><span class="text-slate-400">Domain</span><span class="font-mono text-xs text-slate-600 dark:text-slate-300">{{ $tenant->slug }}.{{ config('app.central_domain') }}</span></div>
                <div class="flex justify-between"><span class="text-slate-400">Support E-Mail</span><span class="text-slate-600 dark:text-slate-300">{{ $tenant->branding->support_email ?: '—' }}</span></div>
                <div class="flex justify-between"><span class="text-slate-400">Website</span><span class="text-slate-600 dark:text-slate-300">{{ $tenant->branding->website ?: '—' }}</span></div>
                <div class="flex justify-between"><span class="text-slate-400">Telefon</span><span class="text-slate-600 dark:text-slate-300">{{ $tenant->branding->phone ?: '—' }}</span></div>
                <div class="flex justify-between"><span class="text-slate-400">Ansprechpartner</span><span class="text-slate-600 dark:text-slate-300">{{ trim(($tenant->branding->contact_first_name ?? '').' '.($tenant->branding->contact_last_name ?? '')) ?: '—' }}</span></div>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 lg:col-span-2">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
                <x-icon name="users" class="w-4 h-4 text-slate-400" /> Nutzer &amp; Rollen
            </h3>
            <div class="space-y-2 text-sm max-h-80 overflow-y-auto">
                @forelse ($memberships as $membership)
                    <a href="{{ route('superadmin.users.show', $membership->user) }}" class="flex items-center justify-between border-b border-slate-100 dark:border-slate-700 pb-2 last:border-0 hover:bg-slate-50 dark:hover:bg-slate-700/50 -mx-1 px-1 rounded">
                        <span class="text-slate-700 dark:text-slate-200">{{ $membership->user->name }} <span class="text-slate-400 font-normal">&middot; {{ $membership->user->email }}</span></span>
                        <span class="inline-block px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-xs">{{ $membership->role }}</span>
                    </a>
                @empty
                    <p class="text-slate-500">Noch keine Nutzer.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 mb-6">
        <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
            <x-icon name="chart-bar" class="w-4 h-4 text-slate-400" /> Kursergebnisse
        </h3>
        <table class="w-full text-sm">
            <thead class="text-slate-400 text-left">
                <tr><th class="py-1">Nutzer</th><th class="py-1">Kurs</th><th class="py-1">Fortschritt</th></tr>
            </thead>
            <tbody>
                @forelse ($results as $result)
                    <tr class="border-t border-slate-100 dark:border-slate-700">
                        <td class="py-1.5">{{ $result['entitlement']->user->name }}</td>
                        <td class="py-1.5 text-slate-500">{{ $result['entitlement']->course->name }}</td>
                        <td class="py-1.5 text-slate-500">{{ $result['percent'] }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="py-3 text-slate-500">Noch keine Kurszugänge.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 flex items-center gap-1.5">
                <x-icon name="bookmark" class="w-4 h-4 text-slate-400" /> Gutschein-Codes dieser Bootsschule
            </h3>
            <a href="{{ route('superadmin.coupons.index', ['tenant_id' => $tenant->id]) }}" class="text-sm hover:underline" style="color: #005FD7">Im Coupon-Bereich verwalten &rarr;</a>
        </div>
        <table class="w-full text-sm">
            <thead class="text-slate-400 text-left">
                <tr><th class="py-1">Code</th><th class="py-1">Kurs</th><th class="py-1">Status</th></tr>
            </thead>
            <tbody>
                @forelse ($coupons->take(10) as $coupon)
                    <tr class="border-t border-slate-100 dark:border-slate-700">
                        <td class="py-1.5 font-mono text-xs">{{ $coupon->code }}</td>
                        <td class="py-1.5 text-slate-500">{{ $coupon->course->name }}</td>
                        <td class="py-1.5">
                            @if ($coupon->isRedeemed())
                                <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-500">
                                    eingelöst von {{ $coupon->redeemedBy?->name }}
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700">offen</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="py-3 text-slate-500">Noch keine Codes für diese Bootsschule.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-superadmin-layout>
