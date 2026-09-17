<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Favoriten &amp; Fehler</h2>
    </x-slot>

    <div x-data="{ tab: 'favorites' }">
        <div class="flex gap-1 mb-4 border-b border-slate-200 dark:border-slate-700">
            <button @click="tab = 'favorites'" :class="tab === 'favorites' ? 'border-b-2 font-medium' : 'text-slate-500'"
                    style="border-color: var(--brand-primary, #005FD7)" class="px-4 py-2 text-sm">Meine Favoriten</button>
            <button @click="tab = 'errors'" :class="tab === 'errors' ? 'border-b-2 font-medium' : 'text-slate-500'"
                    style="border-color: var(--brand-primary, #005FD7)" class="px-4 py-2 text-sm">Meine Fehler</button>
        </div>

        <div x-show="tab === 'favorites'" class="space-y-2">
            @forelse ($favorites as $favorite)
                @php($revision = $favorite->question->publishedRevision())
                <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-4 flex items-center justify-between">
                    <div>
                        <div class="text-slate-700 dark:text-slate-200">{{ $revision?->question_text }}</div>
                        <div class="text-xs text-slate-400">{{ $revision?->topic }}</div>
                    </div>
                    <span class="text-amber-400">★</span>
                </div>
            @empty
                <p class="text-slate-500 text-sm">Noch keine Favoriten gespeichert.</p>
            @endforelse
        </div>

        <div x-show="tab === 'errors'" class="space-y-2" style="display: none">
            @forelse ($wrongQuestions as $question)
                @php($revision = $question->publishedRevision())
                <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-4">
                    <div class="text-slate-700 dark:text-slate-200">{{ $revision?->question_text }}</div>
                    <div class="text-xs text-slate-400">{{ $revision?->topic }}</div>
                </div>
            @empty
                <p class="text-slate-500 text-sm">Bisher keine falsch beantworteten Fragen.</p>
            @endforelse
        </div>
    </div>
</x-app-layout>
