<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Statistik</h2>
    </x-slot>

    <a href="{{ route('admin.dashboard') }}" class="text-sm text-slate-600 dark:text-slate-400 hover:underline">&larr; Zurück zum Admin-Bereich</a>

    <div class="mt-4 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <x-stat-tile icon="users" :value="$withAccess" label="Lernende mit aktivem Kurszugang" />
            <x-stat-tile icon="chart-bar" :value="$activeLearners30" label="Davon aktiv in den letzten 30 Tagen" tone="success" />
            <x-stat-tile icon="check-circle" :value="number_format($answers30, 0, ',', '.')" label="Beantwortete Fragen (30 Tage)" />
        </div>

        <div class="grid lg:grid-cols-2 gap-4">
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4">
                <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Aktive Lernende je Monat</h3>
                @php($max = max(1, $monthly->max('learners')))
                <div class="space-y-2 text-sm">
                    @foreach ($monthly as $month)
                        <div class="flex items-center gap-3">
                            <span class="w-20 shrink-0 text-slate-600 dark:text-slate-400">{{ $month['label'] }}</span>
                            <div class="flex-1 h-3 rounded-full bg-slate-100 dark:bg-slate-700" role="presentation">
                                <div class="h-3 rounded-full" style="width: {{ (int) round($month['learners'] / $max * 100) }}%; background-color: var(--brand-primary, #005FD7)"></div>
                            </div>
                            <span class="w-8 text-right text-slate-700 dark:text-slate-200">{{ $month['learners'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4">
                <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Prüfungssimulation (90 Tage)</h3>
                <div class="space-y-2 text-sm">
                    @forelse ($exams as $row)
                        @php($rate = $row->total ? (int) round($row->passed_count / $row->total * 100) : 0)
                        <div>
                            <div class="flex justify-between text-slate-600 dark:text-slate-300">
                                <span>{{ $row->name }}</span>
                                <span>{{ $rate }} % bestanden <span class="text-xs text-slate-600 dark:text-slate-400">({{ (int) $row->passed_count }}/{{ $row->total }} {{ $row->total == 1 ? 'Versuch' : 'Versuche' }}, {{ $row->learners }} {{ $row->learners == 1 ? 'Person' : 'Personen' }})</span></span>
                            </div>
                            <div class="h-1.5 rounded-full bg-slate-100 dark:bg-slate-700 mt-1" role="presentation"><div class="h-1.5 rounded-full bg-emerald-500" style="width: {{ $rate }}%"></div></div>
                        </div>
                    @empty
                        <p class="text-slate-600 dark:text-slate-400">Noch keine abgeschlossenen Prüfungssimulationen.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 overflow-x-auto">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Kurse</h3>
            <table class="w-full text-sm">
                <thead class="text-left text-slate-600 dark:text-slate-400">
                    <tr><th class="py-1 pr-3 font-medium">Kurs</th><th class="py-1 pr-3 font-medium">Lernende</th><th class="py-1 pr-3 font-medium">Ø Fortschritt</th><th class="py-1 font-medium">Prüfungsreif</th></tr>
                </thead>
                <tbody>
                    @forelse ($courses as $row)
                        <tr class="border-t border-slate-100 dark:border-slate-700">
                            <td class="py-1.5 pr-3 text-slate-700 dark:text-slate-200">{{ $row['name'] }}</td>
                            <td class="py-1.5 pr-3">{{ $row['learners'] }}</td>
                            <td class="py-1.5 pr-3">{{ $row['average'] }} %</td>
                            <td class="py-1.5">{{ $row['ready'] }} von {{ $row['learners'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-2 text-slate-600 dark:text-slate-400">Noch keine aktiven Kurszugänge.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
