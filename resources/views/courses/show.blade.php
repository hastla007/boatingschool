<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">{{ $course->name }}</h2>
    </x-slot>

    <div class="grid lg:grid-cols-[1fr_320px] gap-4 mb-6">
        @if ($hasVideoCourse)
            <a href="{{ route('video.index', $course) }}" class="rounded-2xl p-6 text-white flex items-center gap-5 hover:opacity-95 transition"
               style="background: linear-gradient(120deg, var(--brand-primary, #005FD7), color-mix(in srgb, var(--brand-primary, #005FD7) 55%, #001233));">
                <x-progress-ring :percent="$videoPercent" :size="72" :stroke="6" class="shrink-0 [&_circle:first-child]:stroke-white/25" />
                <div>
                    <div class="font-semibold text-lg">{{ $course->name }}</div>
                    <div class="text-white/80 text-sm">Dein Videokurs für {{ $course->name }}</div>
                    <div class="text-white/90 text-sm mt-1 inline-flex items-center gap-1">
                        {{ $videoPercent >= 100 ? 'Abgeschlossen' : 'Weiterschauen' }} <x-icon name="arrow-right" class="w-3.5 h-3.5" />
                    </div>
                </div>
            </a>
        @else
            <div class="rounded-2xl p-6 bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400 text-sm">
                Für diesen Kurs ist noch kein Videokurs hinterlegt.
            </div>
        @endif

        <a href="{{ route('progress.show', $course) }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex flex-col items-center justify-center hover:shadow-md transition">
            <div class="text-sm font-medium text-slate-600 dark:text-slate-300 mb-2">Kursfortschritt</div>
            <x-progress-ring :percent="$overallPercent" :size="88" :stroke="8" />
            <div class="flex items-center gap-3 text-xs text-slate-400 mt-2">
                <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full" style="background-color: var(--brand-secondary, #00A8A8)"></span> Gefestigt</span>
                <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-slate-200 dark:bg-slate-600"></span> Offen</span>
            </div>
        </a>
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
        <a href="{{ route('learning.overview', $course) }}" class="relative rounded-2xl p-4 h-28 flex flex-col justify-between text-white overflow-hidden bg-gradient-to-br from-teal-500 to-cyan-600 hover:opacity-95 transition">
            <x-icon name="bolt" class="w-6 h-6 text-white/60 self-end" />
            <div>
                <div class="font-semibold">Smart-Learning</div>
                <div class="text-xs text-white/80">Smarttrainer wählt die nächste Frage für dich</div>
            </div>
        </a>

        @if ($knotenModuleId)
            <a href="{{ route('video.index', $course) }}?kapitel={{ $knotenModuleId }}" class="relative rounded-2xl p-4 h-28 flex flex-col justify-between text-white overflow-hidden bg-gradient-to-br from-blue-600 to-cyan-500 hover:opacity-95 transition">
                <x-icon name="academic-cap" class="w-6 h-6 text-white/60 self-end" />
                <div>
                    <div class="font-semibold">Knoten</div>
                    <div class="text-xs text-white/80">{{ $knotenPercent }}% angesehen</div>
                </div>
            </a>
        @endif

        @if ($navigationModuleId)
            <a href="{{ route('video.index', $course) }}?kapitel={{ $navigationModuleId }}" class="relative rounded-2xl p-4 h-28 flex flex-col justify-between text-white overflow-hidden bg-gradient-to-br from-sky-500 to-indigo-600 hover:opacity-95 transition">
                <x-icon name="flag" class="w-6 h-6 text-white/60 self-end" />
                <div>
                    <div class="font-semibold">Navigation</div>
                    <div class="text-xs text-white/80">{{ $navigationPercent }}% angesehen</div>
                </div>
            </a>
        @endif

        <a href="{{ route('exam.intro', $course) }}" class="relative rounded-2xl p-4 h-28 flex flex-col justify-between text-white overflow-hidden bg-gradient-to-br from-indigo-600 to-blue-700 hover:opacity-95 transition">
            <x-icon name="clipboard-document-check" class="w-6 h-6 text-white/60 self-end" />
            <div>
                <div class="font-semibold">Prüfungssimulation</div>
                <div class="text-xs text-white/80">{{ $hasExam ? 'Bereit zum Starten' : 'Noch nicht freigegeben' }}</div>
            </div>
        </a>

        @if ($hasNavigationTasks)
            <a href="{{ route('exam.navigation.index', $course) }}" class="relative rounded-2xl p-4 h-28 flex flex-col justify-between text-white overflow-hidden bg-gradient-to-br from-amber-500 to-orange-600 hover:opacity-95 transition">
                <x-icon name="compass" class="w-6 h-6 text-white/60 self-end" />
                <div>
                    <div class="font-semibold">Navigationsaufgaben</div>
                    <div class="text-xs text-white/80">Übung mit Musterlösung</div>
                </div>
            </a>
        @endif

        @if ($praxisModuleId)
            <a href="{{ route('video.index', $course) }}?kapitel={{ $praxisModuleId }}" class="relative rounded-2xl p-4 h-28 flex flex-col justify-between text-white overflow-hidden bg-gradient-to-br from-rose-500 to-red-600 hover:opacity-95 transition">
                <x-icon name="bolt" class="w-6 h-6 text-white/60 self-end" />
                <div>
                    <div class="font-semibold">Praxisvideos</div>
                    <div class="text-xs text-white/80">{{ $praxisPercent }}% angesehen</div>
                </div>
            </a>
        @endif

        @if ($overallPercent >= $examReadinessThreshold)
            <a href="{{ route('praxis-pruefung.index', $course) }}" class="relative rounded-2xl p-4 h-28 flex flex-col justify-between text-white overflow-hidden bg-gradient-to-br from-emerald-600 to-teal-700 hover:opacity-95 transition">
                <x-icon name="shield-check" class="w-6 h-6 text-white/60 self-end" />
                <div>
                    <div class="font-semibold">Praxis &amp; Prüfung</div>
                    <div class="text-xs text-white/80">Jetzt buchbar</div>
                </div>
            </a>
        @else
            <div class="relative rounded-2xl p-4 h-28 flex flex-col justify-between overflow-hidden bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500 cursor-not-allowed"
                 title="Ab {{ $examReadinessThreshold }}% Kursfortschritt buchbar">
                <x-icon name="lock-closed" class="w-6 h-6 text-slate-300 dark:text-slate-600 self-end" />
                <div>
                    <div class="font-semibold">Praxis &amp; Prüfung</div>
                    <div class="text-xs">Ab {{ $examReadinessThreshold }}% Kursfortschritt &middot; {{ $overallPercent }}%/{{ $examReadinessThreshold }}%</div>
                </div>
            </div>
        @endif
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 flex flex-col sm:flex-row items-center justify-between gap-4">
        <div class="text-center sm:text-left">
            <h3 class="font-semibold text-slate-800 dark:text-white text-lg">Lerne auch auf deinem Handy!</h3>
            <p class="text-sm text-slate-500 mt-1">Unsere App ist bald verfügbar &mdash; lerne unterwegs, wo und wann du willst.</p>
        </div>
        <div class="flex items-center gap-3 shrink-0">
            <span class="inline-flex items-center gap-2.5 px-4 py-2.5 rounded-xl bg-slate-900 text-white opacity-60 cursor-not-allowed" title="Bald verfügbar">
                <svg viewBox="0 0 24 24" class="w-6 h-6 shrink-0" fill="currentColor">
                    <path d="M12.152 6.896c-.948 0-2.415-1.078-3.96-1.04-2.04.027-3.91 1.183-4.961 3.014-2.117 3.675-.546 9.103 1.519 12.09 1.013 1.454 2.208 3.09 3.792 3.039 1.52-.065 2.09-.987 3.935-.987 1.831 0 2.35.987 3.96.948 1.637-.026 2.676-1.48 3.676-2.948 1.156-1.688 1.636-3.325 1.662-3.415-.039-.013-3.182-1.221-3.22-4.857-.026-3.04 2.48-4.494 2.597-4.559-1.429-2.09-3.623-2.324-4.39-2.376-2-.156-3.675 1.09-4.61 1.09zm3.415-3.132c.843-1.012 1.4-2.427 1.245-3.83-1.207.052-2.662.805-3.532 1.818-.78.896-1.454 2.338-1.273 3.714 1.338.104 2.715-.688 3.559-1.702" />
                </svg>
                <span class="text-left leading-tight">
                    <span class="block text-[10px]">Bald verfügbar auf dem</span>
                    <span class="block text-sm font-semibold -mt-0.5">App Store</span>
                </span>
            </span>
            <span class="inline-flex items-center gap-2.5 px-4 py-2.5 rounded-xl bg-slate-900 text-white opacity-60 cursor-not-allowed" title="Bald verfügbar">
                <svg viewBox="0 0 24 24" class="w-6 h-6 shrink-0" fill="currentColor">
                    <path d="M3.609 1.814L13.792 12 3.61 22.186a1.5 1.5 0 01-.79-1.317V3.13a1.5 1.5 0 01.79-1.316zm10.831 10.831l2.86 2.86-11.86 6.803a1.501 1.501 0 01-.451.166zm4.05-4.05l3.144 1.813c.72.417.72 1.46 0 1.876l-3.144 1.813-3.14-3.14zm-14.02-6.782a1.501 1.501 0 01.45.166l11.86 6.802-2.85 2.851z" />
                </svg>
                <span class="text-left leading-tight">
                    <span class="block text-[10px]">Bald verfügbar bei</span>
                    <span class="block text-sm font-semibold -mt-0.5">Google Play</span>
                </span>
            </span>
        </div>
    </div>
</x-app-layout>
