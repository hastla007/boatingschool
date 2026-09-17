<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">{{ $course->name }}</h2>
    </x-slot>

    <div class="max-w-xl mx-auto bg-white dark:bg-slate-800 rounded-xl shadow-sm p-8 text-center">
        <div class="text-3xl mb-2">🎉</div>
        <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-1">Keine Fragen in diesem Modus verfügbar</h3>
        <p class="text-sm text-slate-400 mb-4">
            @switch($mode)
                @case('new') Du hast bereits alle Fragen dieses Kurses gesehen. @break
                @case('wrong') Aktuell gibt es keine offen falsch beantworteten Fragen. @break
                @case('favorites') Du hast noch keine Fragen als Favorit gespeichert. @break
                @default Für diesen Modus stehen aktuell keine Fragen bereit.
            @endswitch
        </p>
        <a href="{{ route('learning.show', $course) }}" class="px-5 py-2 rounded-lg text-white text-sm font-medium" style="background-color: var(--brand-primary, #005FD7)">
            Zum Smarttrainer
        </a>
    </div>
</x-app-layout>
