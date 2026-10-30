<x-app-layout>
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-2 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-800 dark:text-white">Bootsschul-Admin</h1>
            {{-- Bug-Review (Du/Sie im Admin-Bereich gemischt): der restliche
                 Admin-Bereich siezt durchgehend (siehe tabs/dashboard.blade.php). --}}
            <p class="text-sm text-slate-600 dark:text-slate-400 mt-0.5">Verwalten Sie Teilnehmer, Kurse und Einstellungen Ihrer Bootsschule.</p>
        </div>
        <div class="flex items-center gap-2 text-slate-600 dark:text-slate-400 text-xs text-right">
            <span class="leading-tight">Alles im Blick.<br>Immer startklar.</span>
            <x-icon name="anchor" class="w-5 h-5 shrink-0" style="color: var(--brand-secondary, #007A7A)" />
        </div>
    </div>

    {{-- Bug-Review (Willkommensbox-Tab scrollt auf dem Handy seitlich): die
         Tab-Leiste scrollt bei vielen Tabs bereits korrekt horizontal
         (overflow-x-auto), startete bisher aber immer bei der
         Scroll-Position 0 -- ein Aufruf mit ?tab=willkommensbox (z. B. über
         einen direkten Link) oder generell jeder Tab, der nicht unter die
         ersten sichtbaren passt, landete dadurch auf einem Tab-Button, der
         bei 360px bereits außerhalb des sichtbaren Bereichs lag: die Seite
         zeigte "Dashboard | Teilnehmer | Praxis-Anmeldun…", ohne erkennbar
         zu machen, dass tatsächlich "Willkommensbox" aktiv ist, und ohne
         dass die Tab-Leiste von selbst dorthin scrollte. data-tab an jedem
         Button plus scrollIntoView() beim Laden UND bei jedem Tab-Wechsel
         bringt den jeweils aktiven Button zuverlässig in den sichtbaren
         Bereich. --}}
    {{-- Bug-Review BB15 (Admin-Pagination verliert aktiven Reiter beim
         Seitenwechsel): die Pagination-Links in den Tabs (Teilnehmer,
         Praxis-Anmeldungen, Willkommensbox, Coupon-Codes) werden
         serverseitig mit withQueryString() gerendert -- das übernimmt nur
         die Query-Parameter des GERADE AUSGEFÜHRTEN Requests. Der
         Tab-Wechsel selbst läuft aber rein clientseitig über
         history.replaceState() (siehe $watch unten), ohne eigenen Request:
         wechselt man z. B. von "Dashboard" zu "Teilnehmer" und klickt dann
         auf "nächste Seite", enthält der beim ersten Laden gerenderte Link
         noch kein tab=participants, die Navigation landet auf einer neuen
         Seite ohne tab-Parameter, und der aktive Reiter springt zurück auf
         "Dashboard". syncPaginationLinksTab() schreibt den aktuellen
         tab-Wert bei jedem Wechsel (und beim initialen Laden) in jeden
         Pagination-Link innerhalb dieses Tab-Bereichs. --}}
    <div x-data="{ tab: new URLSearchParams(window.location.search).get('tab') || 'dashboard' }"
         x-init="
             const syncPaginationLinksTab = (value) => {
                 $el.querySelectorAll('a[href*=\'_page=\']').forEach((link) => {
                     const linkUrl = new URL(link.href);
                     linkUrl.searchParams.set('tab', value);
                     link.href = linkUrl.toString();
                 });
             };
             $watch('tab', (value) => {
                 const url = new URL(window.location);
                 url.searchParams.set('tab', value);
                 window.history.replaceState({}, '', url);
                 syncPaginationLinksTab(value);
                 $nextTick(() => $el.querySelector(`[data-tab='${value}']`)?.scrollIntoView({ inline: 'nearest', block: 'nearest' }));
             });
             syncPaginationLinksTab(tab);
             $nextTick(() => $el.querySelector(`[data-tab='${tab}']`)?.scrollIntoView({ inline: 'nearest', block: 'nearest' }));
         ">
        <div class="flex gap-1 mb-6 border-b border-slate-200 dark:border-slate-700 overflow-x-auto">
            <button type="button" data-tab="dashboard" @click="tab = 'dashboard'" :class="tab === 'dashboard' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500 dark:text-slate-400'"
                    :style="tab === 'dashboard' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition">Dashboard</button>
            <button type="button" data-tab="participants" @click="tab = 'participants'" :class="tab === 'participants' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500 dark:text-slate-400'"
                    :style="tab === 'participants' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition">Teilnehmer</button>
            <button type="button" data-tab="praxis-anmeldungen" @click="tab = 'praxis-anmeldungen'" :class="tab === 'praxis-anmeldungen' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500 dark:text-slate-400'"
                    :style="tab === 'praxis-anmeldungen' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition">Praxis-Anmeldungen</button>
            <button type="button" data-tab="willkommensbox" @click="tab = 'willkommensbox'" :class="tab === 'willkommensbox' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500 dark:text-slate-400'"
                    :style="tab === 'willkommensbox' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition">Willkommensbox</button>
            @if ($canManageTenant)
                <button type="button" data-tab="coupons" @click="tab = 'coupons'" :class="tab === 'coupons' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500 dark:text-slate-400'"
                        :style="tab === 'coupons' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition">Coupon-Codes</button>
                <button type="button" data-tab="courses" @click="tab = 'courses'" :class="tab === 'courses' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500 dark:text-slate-400'"
                        :style="tab === 'courses' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition">Kursauswahl</button>
            @endif
            <button type="button" data-tab="orte" @click="tab = 'orte'" :class="tab === 'orte' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500 dark:text-slate-400'"
                    :style="tab === 'orte' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition">Orte</button>
            @if ($canManageTenant)
                <button type="button" data-tab="branding" @click="tab = 'branding'" :class="tab === 'branding' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500 dark:text-slate-400'"
                        :style="tab === 'branding' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition">Branding</button>
                <button type="button" data-tab="addons" @click="tab = 'addons'" :class="tab === 'addons' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500 dark:text-slate-400'"
                        :style="tab === 'addons' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition">Addons</button>
                <button type="button" data-tab="course-promo-banners" @click="tab = 'course-promo-banners'" :class="tab === 'course-promo-banners' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500 dark:text-slate-400'"
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
