<x-superadmin-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <div class="text-xs text-slate-600 dark:text-slate-400"><a href="{{ route('superadmin.courses.show', $course) }}" class="hover:underline">{{ $course->name }}</a></div>
                <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Praxistrainer</h1>
            </div>
            <a href="{{ route('superadmin.praxis-tasks.create', $course) }}" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90 transition">Neue Karte</a>
        </div>
    </x-slot>

    <div class="space-y-4">
        <p class="text-sm text-slate-600 dark:text-slate-400">
            {{ $activeCount }} aktive Karten. Deaktivierte Karten sehen Teilnehmer nicht mehr; ihr Lernstand bleibt erhalten und sie lassen sich jederzeit wieder aktivieren.
        </p>

        @forelse ($groups as $kategorie => $tasks)
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
                <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">{{ $kategorie }} <span class="text-xs font-normal text-slate-600 dark:text-slate-400">({{ $tasks->count() }})</span></h3>
                <div class="space-y-1.5">
                    @foreach ($tasks as $task)
                        <div id="karte-{{ $task->id }}" class="flex items-center gap-3 border border-slate-100 dark:border-slate-700 rounded-xl px-3 py-2 text-sm scroll-mt-20 {{ $task->active ? '' : 'opacity-60' }}">
                            @if ($task->media)
                                <img src="{{ $task->media->storage_path }}" alt="" class="w-10 h-10 object-cover rounded-lg shrink-0">
                            @else
                                <span class="w-10 h-10 rounded-lg bg-slate-100 dark:bg-slate-700 shrink-0"></span>
                            @endif
                            <a href="{{ route('superadmin.praxis-tasks.edit', $task) }}" class="flex-1 min-w-0 hover:underline">
                                <span class="block text-slate-700 dark:text-slate-200 truncate">{{ $task->frage }}</span>
                                <span class="block text-xs text-slate-600 dark:text-slate-400 truncate">{{ $task->unterkategorie }}{{ $task->unterkategorie ? ' · ' : '' }}{{ $task->content_id }}</span>
                            </a>
                            @unless ($task->active)
                                <span class="text-xs px-2 py-0.5 rounded-full bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200 shrink-0">deaktiviert</span>
                            @endunless
                            <form method="POST" action="{{ route('superadmin.praxis-tasks.toggle-active', $task) }}" class="shrink-0">
                                @csrf
                                <button type="submit" class="text-xs {{ $task->active ? 'text-rose-600' : 'text-emerald-700 dark:text-emerald-300' }} hover:underline">{{ $task->active ? 'Deaktivieren' : 'Aktivieren' }}</button>
                            </form>
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <p class="text-sm text-slate-600 dark:text-slate-400">Für diesen Kurs gibt es noch keine Praxistrainer-Karten.</p>
        @endforelse
    </div>
</x-superadmin-layout>
