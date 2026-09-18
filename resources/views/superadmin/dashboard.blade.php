<x-superadmin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Superadmin-Dashboard</h2>
    </x-slot>

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-6">
        <x-stat-tile icon="swatch" :value="$tenantCount" label="Bootsschulen" tone="brand" />
        <x-stat-tile icon="users" :value="$userCount" label="Nutzer" />
        <x-stat-tile icon="academic-cap" :value="$activeEntitlementCount" label="Aktive Kurszugänge" tone="success" />
        <x-stat-tile icon="book-open" :value="$courseCount" label="Kurse im Katalog" />
        <x-stat-tile icon="bookmark" :value="$couponRedeemed.'/'.$couponTotal" label="Coupons eingelöst" tone="warning" />
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
        <a href="{{ route('superadmin.tenants.index') }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-3 hover:shadow-md transition">
            <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white shrink-0" style="background-color: #005FD7">
                <x-icon name="swatch" class="w-5 h-5" />
            </div>
            <div>
                <div class="font-medium text-slate-700 dark:text-slate-200">Bootsschulen</div>
                <div class="text-xs text-slate-400">Anlegen &amp; verwalten</div>
            </div>
        </a>
        <a href="{{ route('superadmin.users.index') }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-3 hover:shadow-md transition">
            <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white shrink-0 bg-emerald-600">
                <x-icon name="users" class="w-5 h-5" />
            </div>
            <div>
                <div class="font-medium text-slate-700 dark:text-slate-200">Nutzer</div>
                <div class="text-xs text-slate-400">Daten &amp; Kursergebnisse</div>
            </div>
        </a>
        <a href="{{ route('superadmin.courses.index') }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-3 hover:shadow-md transition">
            <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white shrink-0 bg-indigo-600">
                <x-icon name="book-open" class="w-5 h-5" />
            </div>
            <div>
                <div class="font-medium text-slate-700 dark:text-slate-200">Kurse &amp; Fragen</div>
                <div class="text-xs text-slate-400">Inhalte editieren</div>
            </div>
        </a>
        <a href="{{ route('superadmin.products.index') }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-3 hover:shadow-md transition">
            <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white shrink-0 bg-teal-600">
                <x-icon name="cube" class="w-5 h-5" />
            </div>
            <div>
                <div class="font-medium text-slate-700 dark:text-slate-200">Produkte</div>
                <div class="text-xs text-slate-400">Einzelleistungen verwalten</div>
            </div>
        </a>
        <a href="{{ route('superadmin.coupons.index') }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-3 hover:shadow-md transition">
            <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white shrink-0 bg-amber-500">
                <x-icon name="bookmark" class="w-5 h-5" />
            </div>
            <div>
                <div class="font-medium text-slate-700 dark:text-slate-200">Coupons</div>
                <div class="text-xs text-slate-400">Codes erzeugen &amp; auswerten</div>
            </div>
        </a>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4">
        <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
            <x-icon name="clock" class="w-4 h-4 text-slate-400" /> Zuletzt angelegte Bootsschulen
        </h3>
        <div class="space-y-2 text-sm">
            @forelse ($recentTenants as $tenant)
                <a href="{{ route('superadmin.tenants.show', $tenant) }}" class="flex justify-between border-b border-slate-100 dark:border-slate-700 pb-2 last:border-0 last:pb-0 hover:text-slate-900 dark:hover:text-white">
                    <span class="text-slate-600 dark:text-slate-300">{{ $tenant->name }}</span>
                    <span class="text-slate-400">{{ $tenant->tenant_users_count }} Nutzer</span>
                </a>
            @empty
                <p class="text-slate-500">Noch keine Bootsschulen angelegt.</p>
            @endforelse
        </div>
    </div>
</x-superadmin-layout>
