<x-app-layout>
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-2 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-800 dark:text-white">Bootsschul-Admin</h1>
            {{-- Bug-Review (Du/Sie im Admin-Bereich gemischt): der restliche
                 Admin-Bereich siezt durchgehend (siehe tabs/dashboard.blade.php). --}}
            <p class="text-sm text-slate-600 dark:text-slate-400 mt-0.5">{{ ($canManageTenant ?? false) ? 'Verwalten Sie Teilnehmer, Kurse und Einstellungen Ihrer Bootsschule.' : 'Begleiten Sie Ihre Teilnehmer beim Lernen und bei der Praxis.' }}</p>
        </div>
        <div class="flex items-center gap-2 text-slate-600 dark:text-slate-400 text-xs text-right">
            <span class="leading-tight">Alles im Blick.<br>Immer startklar.</span>
            <x-icon name="anchor" class="w-5 h-5 shrink-0" style="color: var(--brand-secondary, #007A7A)" />
        </div>
    </div>

    @include('partials.platform-announcements')

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
         Praxis-Anmeldungen, Willkommensbox, Zugangscodes) werden
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
    <div x-data="{
             tab: new URLSearchParams(window.location.search).get('tab') || 'dashboard',
             moveTab(event, step) {
                 const tabs = [...event.currentTarget.querySelectorAll('[role=tab]')];
                 const current = Math.max(0, tabs.indexOf(document.activeElement));
                 const next = step === 'first' ? 0 : (step === 'last' ? tabs.length - 1 : (current + step + tabs.length) % tabs.length);
                 tabs[next].focus();
                 tabs[next].click();
             },
         }"
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
        <div class="flex flex-col sm:flex-row sm:items-end gap-1 mb-6 border-b border-slate-200 dark:border-slate-700">
        {{-- Tabs scrollen bei Platzmangel; die eigenen Seiten (Statistik,
             Mitteilungen, Protokoll) stehen getrennt und bleiben sichtbar. --}}
        {{-- Barrierefreiheit (WCAG 2.1.1/4.1.2): Tab-Muster nach WAI-ARIA --
             Pfeiltasten/Pos1/Ende wechseln den Reiter, nur der aktive Reiter
             liegt in der Tab-Reihenfolge, Reiter und Bereich sind über
             aria-controls/aria-labelledby verknüpft. --}}
        {{-- Ab md umbrechen statt seitlich scrollen: bei 1280px lagen sonst
             Reiter ohne Scroll-Hinweis außerhalb des sichtbaren Bereichs
             (scrollWidth > clientWidth). Auf dem Handy bleibt das Wischen. --}}
        <div class="flex gap-1 overflow-x-auto md:flex-wrap md:overflow-x-visible min-w-0 flex-1" role="tablist" aria-label="Bereiche des Admin-Bereichs"
             @keydown.arrow-right.prevent="moveTab($event, 1)" @keydown.arrow-left.prevent="moveTab($event, -1)"
             @keydown.home.prevent="moveTab($event, 'first')" @keydown.end.prevent="moveTab($event, 'last')">
            <button type="button" role="tab" id="admin-tab-dashboard" aria-controls="admin-panel-dashboard" :aria-selected="(tab === 'dashboard').toString()" :tabindex="tab === 'dashboard' ? 0 : -1" data-tab="dashboard" @click="tab = 'dashboard'" :class="tab === 'dashboard' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500 dark:text-slate-400'"
                    :style="tab === 'dashboard' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500">Dashboard</button>
            <button type="button" role="tab" id="admin-tab-participants" aria-controls="admin-panel-participants" :aria-selected="(tab === 'participants').toString()" :tabindex="tab === 'participants' ? 0 : -1" data-tab="participants" @click="tab = 'participants'" :class="tab === 'participants' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500 dark:text-slate-400'"
                    :style="tab === 'participants' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500">Teilnehmer</button>
            <button type="button" role="tab" id="admin-tab-praxis-anmeldungen" aria-controls="admin-panel-praxis-anmeldungen" :aria-selected="(tab === 'praxis-anmeldungen').toString()" :tabindex="tab === 'praxis-anmeldungen' ? 0 : -1" data-tab="praxis-anmeldungen" @click="tab = 'praxis-anmeldungen'" :class="tab === 'praxis-anmeldungen' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500 dark:text-slate-400'"
                    :style="tab === 'praxis-anmeldungen' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500">Praxis-Anmeldungen</button>
            <button type="button" role="tab" id="admin-tab-willkommensbox" aria-controls="admin-panel-willkommensbox" :aria-selected="(tab === 'willkommensbox').toString()" :tabindex="tab === 'willkommensbox' ? 0 : -1" data-tab="willkommensbox" @click="tab = 'willkommensbox'" :class="tab === 'willkommensbox' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500 dark:text-slate-400'"
                    :style="tab === 'willkommensbox' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500">Willkommensbox</button>
            @if ($canManageTenant)
                <button type="button" role="tab" id="admin-tab-coupons" aria-controls="admin-panel-coupons" :aria-selected="(tab === 'coupons').toString()" :tabindex="tab === 'coupons' ? 0 : -1" data-tab="coupons" @click="tab = 'coupons'" :class="tab === 'coupons' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500 dark:text-slate-400'"
                        :style="tab === 'coupons' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500">Zugangscodes</button>
                <button type="button" role="tab" id="admin-tab-courses" aria-controls="admin-panel-courses" :aria-selected="(tab === 'courses').toString()" :tabindex="tab === 'courses' ? 0 : -1" data-tab="courses" @click="tab = 'courses'" :class="tab === 'courses' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500 dark:text-slate-400'"
                        :style="tab === 'courses' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500">Kursauswahl</button>
                <button type="button" role="tab" id="admin-tab-learning-groups" aria-controls="admin-panel-learning-groups" :aria-selected="(tab === 'learning-groups').toString()" :tabindex="tab === 'learning-groups' ? 0 : -1" data-tab="learning-groups" @click="tab = 'learning-groups'" :class="tab === 'learning-groups' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500 dark:text-slate-400'"
                        :style="tab === 'learning-groups' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500">Lerngruppen</button>
            @endif
            <button type="button" role="tab" id="admin-tab-orte" aria-controls="admin-panel-orte" :aria-selected="(tab === 'orte').toString()" :tabindex="tab === 'orte' ? 0 : -1" data-tab="orte" @click="tab = 'orte'" :class="tab === 'orte' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500 dark:text-slate-400'"
                    :style="tab === 'orte' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500">Orte</button>
            @if ($canManageTenant)
                <button type="button" role="tab" id="admin-tab-branding" aria-controls="admin-panel-branding" :aria-selected="(tab === 'branding').toString()" :tabindex="tab === 'branding' ? 0 : -1" data-tab="branding" @click="tab = 'branding'" :class="tab === 'branding' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500 dark:text-slate-400'"
                        :style="tab === 'branding' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500">Branding</button>
                <button type="button" role="tab" id="admin-tab-addons" aria-controls="admin-panel-addons" :aria-selected="(tab === 'addons').toString()" :tabindex="tab === 'addons' ? 0 : -1" data-tab="addons" @click="tab = 'addons'" :class="tab === 'addons' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500 dark:text-slate-400'"
                        :style="tab === 'addons' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500">Addons</button>
                <button type="button" role="tab" id="admin-tab-course-promo-banners" aria-controls="admin-panel-course-promo-banners" :aria-selected="(tab === 'course-promo-banners').toString()" :tabindex="tab === 'course-promo-banners' ? 0 : -1" data-tab="course-promo-banners" @click="tab = 'course-promo-banners'" :class="tab === 'course-promo-banners' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500 dark:text-slate-400'"
                        :style="tab === 'course-promo-banners' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm whitespace-nowrap transition focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500">Kursempfehlungen</button>
            @endif
        </div>
        <div class="flex gap-1 shrink-0 overflow-x-auto">
            {{-- Eigene Seiten (keine Tabs): Statistik, Mitteilungen, Protokoll. --}}
            <a href="{{ route('admin.statistics') }}" class="px-4 py-2 text-sm whitespace-nowrap border-b-2 border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-white">Statistik</a>
            @if ($canManageTenant)
                <a href="{{ route('admin.announcements.index') }}" class="px-4 py-2 text-sm whitespace-nowrap border-b-2 border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-white">Mitteilungen</a>
                <a href="{{ route('admin.audit.index') }}" class="px-4 py-2 text-sm whitespace-nowrap border-b-2 border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-white">Protokoll</a>
            @endif
        </div>
        </div>

        <div x-show="tab === 'dashboard'" role="tabpanel" id="admin-panel-dashboard" aria-labelledby="admin-tab-dashboard">
            @include('admin.tabs.dashboard')
        </div>
        <div x-show="tab === 'participants'" role="tabpanel" id="admin-panel-participants" aria-labelledby="admin-tab-participants" style="display: none">
            @include('admin.tabs.participants')
        </div>
        <div x-show="tab === 'praxis-anmeldungen'" role="tabpanel" id="admin-panel-praxis-anmeldungen" aria-labelledby="admin-tab-praxis-anmeldungen" style="display: none">
            @include('admin.tabs.praxis-anmeldungen')
        </div>
        <div x-show="tab === 'willkommensbox'" role="tabpanel" id="admin-panel-willkommensbox" aria-labelledby="admin-tab-willkommensbox" style="display: none">
            @include('admin.tabs.willkommensbox')
        </div>
        @if ($canManageTenant)
            <div x-show="tab === 'coupons'" role="tabpanel" id="admin-panel-coupons" aria-labelledby="admin-tab-coupons" style="display: none">
                @include('admin.tabs.coupons')
            </div>
            <div x-show="tab === 'courses'" role="tabpanel" id="admin-panel-courses" aria-labelledby="admin-tab-courses" style="display: none">
                @include('admin.tabs.courses')
            </div>
            <div x-show="tab === 'learning-groups'" role="tabpanel" id="admin-panel-learning-groups" aria-labelledby="admin-tab-learning-groups" style="display: none">
                @include('admin.tabs.learning-groups')
            </div>
        @endif
        <div x-show="tab === 'orte'" role="tabpanel" id="admin-panel-orte" aria-labelledby="admin-tab-orte" style="display: none">
            @include('admin.tabs.orte')
        </div>
        @if ($canManageTenant)
            <div x-show="tab === 'branding'" role="tabpanel" id="admin-panel-branding" aria-labelledby="admin-tab-branding" style="display: none">
                @include('admin.tabs.branding')
            </div>
            <div x-show="tab === 'addons'" role="tabpanel" id="admin-panel-addons" aria-labelledby="admin-tab-addons" style="display: none">
                @include('admin.tabs.addons')
            </div>
            <div x-show="tab === 'course-promo-banners'" role="tabpanel" id="admin-panel-course-promo-banners" aria-labelledby="admin-tab-course-promo-banners" style="display: none">
                @include('admin.tabs.course-promo-banners')
            </div>
        @endif
    </div>
</x-app-layout>
