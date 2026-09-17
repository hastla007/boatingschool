<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Prüfungssimulation &middot; {{ $course->name }}</h2>
    </x-slot>

    <div class="max-w-xl mx-auto">
        <div class="flex items-center justify-center gap-2 mb-6 text-xs text-slate-400">
            <span class="flex items-center gap-1.5 font-medium" style="color: var(--brand-primary, #005FD7)">
                <span class="w-5 h-5 rounded-full text-white flex items-center justify-center text-[11px]" style="background-color: var(--brand-primary, #005FD7)">1</span> Einführung
            </span>
            <span class="w-8 h-px bg-slate-200 dark:bg-slate-700"></span>
            <span class="flex items-center gap-1.5">
                <span class="w-5 h-5 rounded-full bg-slate-200 dark:bg-slate-700 text-slate-500 flex items-center justify-center text-[11px]">2</span> Prüfung
            </span>
            <span class="w-8 h-px bg-slate-200 dark:bg-slate-700"></span>
            <span class="flex items-center gap-1.5">
                <span class="w-5 h-5 rounded-full bg-slate-200 dark:bg-slate-700 text-slate-500 flex items-center justify-center text-[11px]">3</span> Ergebnis
            </span>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6">
            @if (! $ruleSet)
                <p class="text-slate-500">Für diesen Kurs liegt noch kein Prüfungsregelwerk vor.</p>
            @elseif (! $ruleSet->isVerified())
                <div class="rounded-xl bg-amber-50 border border-amber-200 text-amber-700 dark:bg-amber-900/30 dark:border-amber-800 dark:text-amber-300 px-4 py-3 text-sm flex items-start gap-2">
                    <x-icon name="lock-closed" class="w-5 h-5 shrink-0 mt-0.5" />
                    <span>Für diesen Kurs ist die Prüfungssimulation vorbereitet, aber das Regelwerk (Fragenmix, Zeitlimit, Bestehensgrenze)
                    ist fachlich noch nicht freigegeben. Die Simulation startet erst nach Verifizierung.</span>
                </div>
            @else
                <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Prüfungsbedingungen</h3>
                <ul class="space-y-2 text-sm text-slate-600 dark:text-slate-300 mb-6">
                    <li class="flex items-center gap-2">
                        <x-icon name="clock" class="w-4 h-4 text-slate-400" /> Zeitlimit: {{ intdiv($ruleSet->time_limit_seconds, 60) }} Minuten
                    </li>
                    <li class="flex items-center gap-2">
                        <x-icon name="clipboard-document-check" class="w-4 h-4 text-slate-400" /> Fragenmix: {{ $ruleSet->blueprints->sum('question_count') }} Fragen aus {{ $ruleSet->blueprints->count() }} Modul(en)
                    </li>
                    <li class="flex items-center gap-2">
                        <x-icon name="shield-check" class="w-4 h-4 text-slate-400" /> Bestehensgrenze: {{ (int) round(($ruleSet->passing_rule['min_correct_ratio'] ?? 0.75) * 100) }}%
                    </li>
                </ul>
                <p class="text-xs text-slate-400 mb-4">
                    Hinweis: Dieses Regelwerk ist eine Demo-Verifizierung für Testzwecke und ersetzt keine amtliche Prüfungsordnung.
                </p>
                <form method="POST" action="{{ route('exam.start', $course) }}">
                    @csrf
                    <button type="submit" class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 rounded-lg text-white font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                        Prüfung starten <x-icon name="arrow-right" class="w-4 h-4" />
                    </button>
                </form>
            @endif
        </div>

        @if ($hasNavigationTasks)
            <a href="{{ route('exam.navigation.index', $course) }}" class="mt-4 flex items-center justify-between gap-3 bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition">
                <span class="flex items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-200">
                    <x-icon name="compass" class="w-5 h-5 text-slate-400" /> Navigationsaufgaben üben
                </span>
                <x-icon name="arrow-right" class="w-4 h-4 text-slate-400" />
            </a>
        @endif
    </div>
</x-app-layout>
