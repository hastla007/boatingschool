<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('courses.show', $course) }}" class="w-8 h-8 rounded-full flex items-center justify-center text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700 shrink-0">
                <x-icon name="chevron-right" class="w-4 h-4 rotate-180" />
            </a>
            <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">{{ $course->name }} &middot; {{ $sectionLabel }} &middot; Favoriten &amp; Fehler</h2>
        </div>
    </x-slot>

    <div x-data="{ tab: 'favorites' }">
        <div class="flex gap-1 mb-4 border-b border-slate-200 dark:border-slate-700">
            <button @click="tab = 'favorites'" :class="tab === 'favorites' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500'"
                    :style="tab === 'favorites' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm transition">Meine Favoriten</button>
            <button @click="tab = 'errors'" :class="tab === 'errors' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500'"
                    :style="tab === 'errors' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm transition">Meine Fehler</button>
        </div>

        <div x-show="tab === 'favorites'" class="space-y-2">
            @forelse ($favorites as $favorite)
                @php($revision = $favorite->question->publishedRevision())
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-50 dark:bg-amber-900/30 flex items-center justify-center shrink-0 text-amber-500">
                        <x-icon name="star" class="w-5 h-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-slate-700 dark:text-slate-200 truncate">{{ $revision?->question_text }}</div>
                        <div class="text-xs text-slate-400">{{ $revision?->topic }}</div>
                    </div>
                </div>
            @empty
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 text-center text-slate-500 text-sm">
                    Noch keine Favoriten gespeichert.
                </div>
            @endforelse
        </div>

        <div x-show="tab === 'errors'" class="space-y-2" style="display: none">
            @forelse ($wrongQuestions as $question)
                @php($revision = $question->publishedRevision())
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-rose-50 dark:bg-rose-900/30 flex items-center justify-center shrink-0 text-rose-500">
                        <x-icon name="x-circle" class="w-5 h-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-slate-700 dark:text-slate-200 truncate">{{ $revision?->question_text }}</div>
                        <div class="text-xs text-slate-400">{{ $revision?->topic }}</div>
                    </div>
                </div>
            @empty
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 text-center text-slate-500 text-sm">
                    Bisher keine falsch beantworteten Fragen.
                </div>
            @endforelse
        </div>
    </div>
</x-app-layout>
