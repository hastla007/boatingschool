<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-1 text-xs text-slate-400 mb-1">
            <a href="{{ route('courses.show', $course) }}" class="hover:underline">{{ $course->name }}</a>
            <x-icon name="chevron-right" class="w-3 h-3" />
            <span>Praxis &amp; Prüfung</span>
        </div>
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Praxis &amp; Prüfung</h2>
    </x-slot>

    <div class="max-w-2xl mx-auto bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-10 text-center">
        <x-icon name="anchor" class="w-10 h-10 mx-auto text-slate-300 mb-3" />
        <p class="text-slate-500 dark:text-slate-400">Diese Seite wird in Kürze verfügbar sein.</p>
    </div>
</x-app-layout>
