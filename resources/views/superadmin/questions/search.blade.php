<x-superadmin-layout>
    <x-slot name="header">
        <div>
            <div class="text-xs text-slate-600 dark:text-slate-400"><a href="{{ route('superadmin.modules.index') }}" class="hover:underline">Module</a></div>
            <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Fragen suchen</h1>
        </div>
    </x-slot>

    <div class="space-y-4">
        <form method="GET" action="{{ route('superadmin.questions.search') }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex gap-2">
            <label for="q" class="sr-only">Suchbegriff</label>
            <input id="q" name="q" type="search" value="{{ $q }}" autofocus placeholder="Fragetext, Antwort, Erklärung, Content-ID oder amtliche Nummer"
                   class="flex-1 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
            <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90">Suchen</button>
        </form>

        @if ($q !== '' && mb_strlen($q) < 2)
            <p class="text-sm text-slate-600 dark:text-slate-400">Bitte mindestens 2 Zeichen eingeben.</p>
        @elseif ($q !== '')
            <p class="text-sm text-slate-600 dark:text-slate-400">{{ $results->count() }}{{ $results->count() === 100 ? '+' : '' }} Treffer</p>
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm divide-y divide-slate-100 dark:divide-slate-700">
                @forelse ($results as $question)
                    @php($revision = $question->revisions->firstWhere('editorial_status', 'published') ?? $question->revisions->first())
                    <a href="{{ route('superadmin.questions.edit', $question) }}" class="block px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-700/50">
                        <div class="flex flex-wrap items-center gap-2 text-xs text-slate-600 dark:text-slate-400 mb-0.5">
                            <span class="font-mono">{{ $question->content_id }}</span>
                            @if ($question->official_number)
                                <span>Nr. {{ $question->official_number }}</span>
                            @endif
                            @foreach ($question->modules as $module)
                                <span class="rounded-full px-2 py-0.5 bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200">{{ $module->name }}</span>
                            @endforeach
                            @unless ($question->active)
                                <span class="rounded-full px-2 py-0.5 bg-slate-200 dark:bg-slate-700">deaktiviert</span>
                            @endunless
                            @if ($question->revisions->contains('editorial_status', 'review'))
                                <span class="rounded-full px-2 py-0.5 bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200">Review ausstehend</span>
                            @endif
                        </div>
                        <div class="text-sm text-slate-700 dark:text-slate-200">{{ \Illuminate\Support\Str::limit($revision?->question_text, 220) }}</div>
                    </a>
                @empty
                    <p class="px-4 py-6 text-sm text-slate-600 dark:text-slate-400">Keine Fragen gefunden.</p>
                @endforelse
            </div>
        @endif
    </div>
</x-superadmin-layout>
