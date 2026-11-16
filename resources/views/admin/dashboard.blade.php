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

    {{-- Nur der aktive Reiter wird berechnet und gerendert
         (AdminDashboardController::TAB_BUILDERS): bisher lagen alle Reiter
         im HTML und wurden per Alpine umgeschaltet (große Schule: ~90
         Abfragen, ~720 KB je Aufruf). Die Reiter sind deshalb Links auf
         ?tab=…, ein Wechsel ist ein normaler Seitenaufruf -- Deep-Links,
         Zurück-Taste, Weiterleitungen nach Formularen (back() bzw.
         ?tab=…) und die Pagination (withQueryString() plus tab, siehe
         Controller) behalten den Reiter damit ohne eigenes JavaScript.

         Barrierefreiheit (WCAG 2.1.1/4.1.2): Tab-Muster nach WAI-ARIA mit
         manueller Aktivierung -- Pfeiltasten/Pos1/Ende verschieben nur den
         Fokus (jede Aktivierung lädt die Seite, ein Laden je Pfeiltaste
         wäre unbedienbar), Enter (Link) bzw. Leertaste öffnen den Reiter.
         Nur der aktive Reiter liegt in der Tab-Reihenfolge und ist über
         aria-controls/aria-labelledby mit dem (einzigen) Bereich verknüpft;
         die übrigen haben kein aria-controls, weil ihr Bereich nicht im
         Dokument steht.

         Bug-Review (Willkommensbox-Tab scrollt auf dem Handy seitlich): die
         Tab-Leiste scrollt auf dem Handy horizontal und startet bei
         Scroll-Position 0 -- ein Aufruf mit ?tab=willkommensbox landete
         sonst auf einem Reiter außerhalb des sichtbaren Bereichs.
         scrollIntoView() beim Laden bringt den aktiven Reiter (data-tab)
         zuverlässig in den sichtbaren Bereich. --}}
    <div x-data="{
             moveTab(event, step) {
                 const tabs = [...event.currentTarget.querySelectorAll('[role=tab]')];
                 const current = Math.max(0, tabs.indexOf(document.activeElement));
                 const next = step === 'first' ? 0 : (step === 'last' ? tabs.length - 1 : (current + step + tabs.length) % tabs.length);
                 tabs[next].focus();
             },
         }"
         x-init="$nextTick(() => $el.querySelector('[data-tab][aria-selected=true]')?.scrollIntoView({ inline: 'nearest', block: 'nearest' }))">
        <div class="flex flex-col sm:flex-row sm:items-end gap-1 mb-6 border-b border-slate-200 dark:border-slate-700">
        {{-- Tabs scrollen bei Platzmangel; die eigenen Seiten (Statistik,
             Mitteilungen, Protokoll) stehen getrennt und bleiben sichtbar. --}}
        {{-- Ab md umbrechen statt seitlich scrollen: bei 1280px lagen sonst
             Reiter ohne Scroll-Hinweis außerhalb des sichtbaren Bereichs
             (scrollWidth > clientWidth). Auf dem Handy bleibt das Wischen. --}}
        <div class="flex gap-1 overflow-x-auto md:flex-wrap md:overflow-x-visible min-w-0 flex-1" role="tablist" aria-label="Bereiche des Admin-Bereichs"
             @keydown.arrow-right.prevent="moveTab($event, 1)" @keydown.arrow-left.prevent="moveTab($event, -1)"
             @keydown.home.prevent="moveTab($event, 'first')" @keydown.end.prevent="moveTab($event, 'last')"
             @keydown.space.prevent="$event.target.closest('[role=tab]')?.click()">
            @foreach ($tabs as $tabKey => $tabLabel)
                @if ($tabKey === $activeTab)
                    <a href="{{ route('admin.dashboard', ['tab' => $tabKey]) }}" role="tab" id="admin-tab-{{ $tabKey }}" aria-controls="admin-panel-{{ $tabKey }}" aria-selected="true" tabindex="0" data-tab="{{ $tabKey }}"
                       class="px-4 py-2 text-sm whitespace-nowrap border-b-2 font-medium text-slate-800 dark:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500" style="border-color: var(--brand-primary, #005FD7)">{{ $tabLabel }}</a>
                @else
                    <a href="{{ route('admin.dashboard', ['tab' => $tabKey]) }}" role="tab" id="admin-tab-{{ $tabKey }}" aria-selected="false" tabindex="-1" data-tab="{{ $tabKey }}"
                       class="px-4 py-2 text-sm whitespace-nowrap border-b-2 border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-white transition focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500">{{ $tabLabel }}</a>
                @endif
            @endforeach
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

        <div role="tabpanel" id="admin-panel-{{ $activeTab }}" aria-labelledby="admin-tab-{{ $activeTab }}">
            @include('admin.tabs.'.$activeTab)
        </div>
    </div>
</x-app-layout>
