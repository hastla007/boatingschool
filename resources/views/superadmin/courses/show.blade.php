<x-superadmin-layout>
    <x-slot name="header">
        <div>
            <div class="text-xs text-slate-400"><a href="{{ route('superadmin.courses.index') }}" class="hover:underline">Kurse</a></div>
            <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">{{ $course->name }}</h2>
        </div>
    </x-slot>

    <div class="grid lg:grid-cols-3 gap-4">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Stammdaten</h3>
            <form method="POST" action="{{ route('superadmin.courses.update', $course) }}" class="space-y-3">
                @csrf
                @method('PATCH')
                <div>
                    <x-input-label for="name" value="Name" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $course->name)" required />
                </div>
                <div>
                    <x-input-label for="course_type" value="Kurstyp" />
                    <x-text-input id="course_type" name="course_type" type="text" class="mt-1 block w-full" :value="old('course_type', $course->course_type)" required />
                </div>
                <div>
                    <x-input-label for="status" value="Status" />
                    <select id="status" name="status" class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                        @foreach (['draft', 'published', 'archived'] as $option)
                            <option value="{{ $option }}" @selected(old('status', $course->status) === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90 transition">Speichern</button>
            </form>
        </div>

        <div class="lg:col-span-2 bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Module dieses Kurses</h3>
            <div class="space-y-2 mb-4">
                @forelse ($course->modules as $module)
                    <div class="flex items-center justify-between border border-slate-100 dark:border-slate-700 rounded-xl px-3 py-2 text-sm">
                        <a href="{{ route('superadmin.questions.index', $module) }}" class="text-slate-700 dark:text-slate-200 hover:underline">{{ $module->name }}</a>
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-slate-400">{{ $module->questions_count }} Fragen</span>
                            <form method="POST" action="{{ route('superadmin.courses.modules.detach', [$course, $module]) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs text-rose-500 hover:underline">Entfernen</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="text-slate-500 text-sm">Noch keine Module zugeordnet.</p>
                @endforelse
            </div>

            @if ($availableModules->isNotEmpty())
                <form method="POST" action="{{ route('superadmin.courses.modules.attach', $course) }}" class="flex gap-2">
                    @csrf
                    <select name="module_id" required class="flex-1 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                        @foreach ($availableModules as $module)
                            <option value="{{ $module->id }}">{{ $module->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="px-3 py-2 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 transition text-sm shrink-0">Modul zuordnen</button>
                </form>
            @endif
        </div>
    </div>
</x-superadmin-layout>
