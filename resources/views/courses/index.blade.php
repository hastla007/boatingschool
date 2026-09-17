<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Meine Kurse</h2>
    </x-slot>

    @if ($courses->isEmpty())
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-6 text-center text-slate-500">
            Noch keine Kurse freigeschaltet.
        </div>
    @else
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach ($courses as $course)
                <a href="{{ route('courses.show', $course) }}" class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-4 hover:shadow-md transition block">
                    <div class="font-medium text-slate-800 dark:text-white">{{ $course->name }}</div>
                    <div class="text-xs text-slate-400 mt-1">{{ $course->modules->pluck('name')->join(' · ') }}</div>
                </a>
            @endforeach
        </div>
    @endif
</x-app-layout>
