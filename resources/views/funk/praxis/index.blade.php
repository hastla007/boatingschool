<x-app-layout>
    <x-slot name="header">
        @include('funk._header', ['title' => 'Praxis-Prüfungssimulation', 'icon' => 'signal', 'percent' => $percent])
    </x-slot>

    <div class="space-y-4">
        @include('funk._tabs', [
            'tabs' => collect($catalogs)->mapWithKeys(fn ($c) => [$c => route('funk.praxis.index', ['course' => $course, 'teil' => $c])])->all(),
            'active' => $catalog,
        ])

        <x-funk-info>
            @if ($catalog === 'SRC')
                Hier übst du die praktische SRC-Prüfung: Notalarm und Notmeldung, Weiterleitung und Bestätigung, Fehlalarm,
                Dringlichkeits-, Sicherheits- und Routineverkehr. Bestimme für jede Aufgabe den Rang, die Bedienung des
                DSC-Controllers, die Einstellung am Funkgerät und den Wortlaut der Meldung. Tipp: Sprich die Meldung laut ein
                &ndash; in der Prüfung setzt du sie an einem echten Funkgerät ab.
            @else
                Hier übst du die praktische UBI-Prüfung: Not-, Dringlichkeits-, Sicherheits- und Routineverkehr auf den
                Binnenschifffahrtsstraßen. Bestimme für jede Aufgabe den Rang, den passenden Verkehrskreis, die Einstellung am
                Funkgerät und den Wortlaut der Meldung. Kanäle der Revierzentralen und Schleusen findest du im Handbuch
                Binnenschifffahrtsfunk und auf den Tafelzeichen an der Strecke.
            @endif
        </x-funk-info>

        <div class="text-sm text-slate-600 dark:text-slate-400">{{ count($completed) }} von {{ count($tasks) }} Aufgaben erledigt</div>

        <div class="grid grid-cols-3 sm:grid-cols-5 md:grid-cols-7 lg:grid-cols-9 gap-3">
            @foreach ($tasks as $task)
                @php $done = in_array($task['key'], $completed, true); @endphp
                <a href="{{ route('funk.praxis.show', ['course' => $course, 'catalog' => $catalog, 'number' => $task['number']]) }}"
                   class="relative aspect-square rounded-2xl flex items-center justify-center text-lg font-semibold shadow-sm hover:shadow-md transition {{ $done ? 'text-white' : 'bg-white dark:bg-slate-800 text-slate-800 dark:text-white' }}"
                   @if ($done) style="background: linear-gradient(150deg, var(--brand-secondary, #007A7A), color-mix(in srgb, var(--brand-secondary, #007A7A) 60%, #001233));" @endif
                   aria-label="Aufgabe {{ $task['number'] }}{{ $done ? ' (erledigt)' : '' }}">
                    {{ $task['number'] }}
                    @if ($done)
                        <x-icon name="check-circle" class="w-4 h-4 absolute top-2 right-2 text-white/80" />
                    @endif
                </a>
            @endforeach
        </div>
    </div>
</x-app-layout>
