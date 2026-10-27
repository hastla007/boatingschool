<x-app-layout>
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-2 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-800 dark:text-white">Bootsschul-Admin</h1>
            <p class="text-sm text-slate-400 mt-0.5">Verwalte Teilnehmer, Kurse und Einstellungen deiner Bootsschule.</p>
        </div>
        <div class="flex items-center gap-2 text-slate-400 dark:text-slate-500 text-xs text-right">
            <span class="leading-tight">Alles im Blick.<br>Immer startklar.</span>
            <x-icon name="anchor" class="w-5 h-5 shrink-0" style="color: var(--brand-secondary, #00A8A8)" />
        </div>
    </div>

    <div x-data="{ tab: new URLSearchParams(window.location.search).get('tab') || 'dashboard' }"
         x-init="$watch('tab', (value) => { const url = new URL(window.location); url.searchParams.set('tab', value); window.history.replaceState({}, '', url); })">
        <div class="flex gap-1 mb-6 border-b border-slate-200 dark:border-slate-700 overflow-x-auto">
            <button type="button" @click="tab = 'dashboard'" :class="tab === 'dashboard' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500'"
                    :style="tab === 'dashboard' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition">Dashboard</button>
            <button type="button" @click="tab = 'participants'" :class="tab === 'participants' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500'"
                    :style="tab === 'participants' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition">Teilnehmer</button>
            <button type="button" @click="tab = 'praxis-anmeldungen'" :class="tab === 'praxis-anmeldungen' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500'"
                    :style="tab === 'praxis-anmeldungen' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition">Praxis-Anmeldungen</button>
            <button type="button" @click="tab = 'willkommensbox'" :class="tab === 'willkommensbox' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500'"
                    :style="tab === 'willkommensbox' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition">Willkommensbox</button>
            @if ($canManageTenant)
                <button type="button" @click="tab = 'coupons'" :class="tab === 'coupons' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500'"
                        :style="tab === 'coupons' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition">Coupon-Codes</button>
                <button type="button" @click="tab = 'courses'" :class="tab === 'courses' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500'"
                        :style="tab === 'courses' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition">Kursauswahl</button>
            @endif
            <button type="button" @click="tab = 'orte'" :class="tab === 'orte' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500'"
                    :style="tab === 'orte' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition">Orte</button>
            @if ($canManageTenant)
                <button type="button" @click="tab = 'branding'" :class="tab === 'branding' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500'"
                        :style="tab === 'branding' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition">Branding</button>
                <button type="button" @click="tab = 'addons'" :class="tab === 'addons' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500'"
                        :style="tab === 'addons' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition">Addons</button>
                <button type="button" @click="tab = 'course-promo-banners'" :class="tab === 'course-promo-banners' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500'"
                        :style="tab === 'course-promo-banners' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition">Kursempfehlungen</button>
            @endif
        </div>

        <div x-show="tab === 'dashboard'">
            @include('admin.tabs.dashboard')
        </div>
        <div x-show="tab === 'participants'" style="display: none">
            @include('admin.tabs.participants')
        </div>
        <div x-show="tab === 'praxis-anmeldungen'" style="display: none">
            @include('admin.tabs.praxis-anmeldungen')
        </div>
        <div x-show="tab === 'willkommensbox'" style="display: none">
            @include('admin.tabs.willkommensbox')
        </div>
        @if ($canManageTenant)
            <div x-show="tab === 'coupons'" style="display: none">
                @include('admin.tabs.coupons')
            </div>
            <div x-show="tab === 'courses'" style="display: none">
                @include('admin.tabs.courses')
            </div>
        @endif
        <div x-show="tab === 'orte'" style="display: none">
            @include('admin.tabs.orte')
        </div>
        @if ($canManageTenant)
            <div x-show="tab === 'branding'" style="display: none">
                @include('admin.tabs.branding')
            </div>
            <div x-show="tab === 'addons'" style="display: none">
                @include('admin.tabs.addons')
            </div>
            <div x-show="tab === 'course-promo-banners'" style="display: none">
                @include('admin.tabs.course-promo-banners')
            </div>
        @endif
    </div>
</x-app-layout>
