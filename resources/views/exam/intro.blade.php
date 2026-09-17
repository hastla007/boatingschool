<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Prüfungssimulation · {{ $course->name }}</h2>
    </x-slot>

    <div class="max-w-xl mx-auto bg-white dark:bg-slate-800 rounded-xl shadow-sm p-6">
        @if (! $ruleSet)
            <p class="text-slate-500">Für diesen Kurs liegt noch kein Prüfungsregelwerk vor.</p>
        @elseif (! $ruleSet->isVerified())
            <div class="rounded-lg bg-amber-50 border border-amber-200 text-amber-700 dark:bg-amber-900/30 dark:border-amber-800 dark:text-amber-300 px-4 py-3 text-sm">
                Für diesen Kurs ist die Prüfungssimulation vorbereitet, aber das Regelwerk (Fragenmix, Zeitlimit, Bestehensgrenze)
                ist fachlich noch nicht freigegeben. Die Simulation startet erst nach Verifizierung.
            </div>
        @else
            <ul class="space-y-2 text-sm text-slate-600 dark:text-slate-300 mb-6">
                <li class="flex items-center gap-2">⏱ Zeitlimit: {{ intdiv($ruleSet->time_limit_seconds, 60) }} Minuten</li>
                <li class="flex items-center gap-2">📋 Fragenmix: {{ $ruleSet->blueprints->sum('question_count') }} Fragen aus {{ $ruleSet->blueprints->count() }} Modul(en)</li>
                <li class="flex items-center gap-2">✅ Bestehensgrenze: {{ (int) round(($ruleSet->passing_rule['min_correct_ratio'] ?? 0.75) * 100) }}%</li>
            </ul>
            <p class="text-xs text-slate-400 mb-4">
                Hinweis: Dieses Regelwerk ist eine Demo-Verifizierung für Testzwecke und ersetzt keine amtliche Prüfungsordnung.
            </p>
            <form method="POST" action="{{ route('exam.start', $course) }}">
                @csrf
                <button type="submit" class="w-full px-5 py-3 rounded-lg text-white font-medium" style="background-color: var(--brand-primary, #005FD7)">
                    Prüfung starten →
                </button>
            </form>
        @endif
    </div>
</x-app-layout>
