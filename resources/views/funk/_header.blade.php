{{-- Kopfzeile des Funk-Trainings: Zurück zum Kurs, Titel, Fortschritt. --}}
<div class="flex items-center gap-3">
    <a href="{{ route('courses.show', $course) }}" aria-label="Zurück zum Kurs" class="w-8 h-8 rounded-full flex items-center justify-center text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700 shrink-0">
        <x-icon name="chevron-right" class="w-4 h-4 rotate-180" />
    </a>
    <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200 flex items-center gap-2">
        <x-icon :name="$icon" class="w-5 h-5 text-slate-600 dark:text-slate-400" /> {{ $title }}
    </h1>
    @isset($percent)
        <div class="flex-1 hidden sm:flex items-center gap-2 max-w-xs ml-4">
            <div class="flex-1 bg-slate-100 dark:bg-slate-700 rounded-full h-1.5">
                <div class="h-1.5 rounded-full" style="width: {{ $percent }}%; background-color: var(--brand-secondary, #007A7A)"></div>
            </div>
            <span class="text-xs text-slate-500 dark:text-slate-400 shrink-0">{{ $percent }}%</span>
        </div>
    @endisset
</div>
