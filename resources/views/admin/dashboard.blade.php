<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Bootsschul-Admin</h2>
    </x-slot>

    <div x-data="{ tab: new URLSearchParams(window.location.search).get('tab') || 'dashboard' }"
         x-init="$watch('tab', (value) => { const url = new URL(window.location); url.searchParams.set('tab', value); window.history.replaceState({}, '', url); })">
        <div class="flex gap-1 mb-6 border-b border-slate-200 dark:border-slate-700 overflow-x-auto">
            <button type="button" @click="tab = 'dashboard'" :class="tab === 'dashboard' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500'"
                    :style="tab === 'dashboard' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition">Dashboard</button>
            <button type="button" @click="tab = 'participants'" :class="tab === 'participants' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500'"
                    :style="tab === 'participants' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition">Teilnehmer</button>
            <button type="button" @click="tab = 'branding'" :class="tab === 'branding' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500'"
                    :style="tab === 'branding' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition">Branding</button>
            <button type="button" @click="tab = 'webshop-links'" :class="tab === 'webshop-links' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500'"
                    :style="tab === 'webshop-links' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition">Webshop-Links</button>
            <button type="button" @click="tab = 'courses'" :class="tab === 'courses' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500'"
                    :style="tab === 'courses' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition">Kursauswahl</button>
            <button type="button" @click="tab = 'coupons'" :class="tab === 'coupons' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500'"
                    :style="tab === 'coupons' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition">Coupon-Codes</button>
        </div>

        <div x-show="tab === 'dashboard'">
            @include('admin.tabs.dashboard')
        </div>
        <div x-show="tab === 'participants'" style="display: none">
            @include('admin.tabs.participants')
        </div>
        <div x-show="tab === 'branding'" style="display: none">
            @include('admin.tabs.branding')
        </div>
        <div x-show="tab === 'webshop-links'" style="display: none">
            @include('admin.tabs.webshop-links')
        </div>
        <div x-show="tab === 'courses'" style="display: none">
            @include('admin.tabs.courses')
        </div>
        <div x-show="tab === 'coupons'" style="display: none">
            @include('admin.tabs.coupons')
        </div>
    </div>
</x-app-layout>
