{{-- Karteikarten-Trainer (Quiz und Übersicht) für Vokabeln und Buchstabiertafel. Erwartet:
     $vocabulary, $vocabLearned, $vocabStreaks, $vocabTotal, $streakToLearn, $answerUrl, $tabUrl,
     $ansicht, $unit ("Vokabeln"/"Buchstaben"), $quizLabel. --}}
@include('funk._tabs', [
    'tabs' => [$quizLabel => $tabUrl(['ansicht' => 'quiz']), 'Übersicht' => $tabUrl(['ansicht' => 'uebersicht'])],
    'active' => $ansicht === 'quiz' ? $quizLabel : 'Übersicht',
])

@if ($ansicht === 'quiz')
    @php
        $open = collect($vocabulary)->flatten(1)
            ->reject(fn ($v) => in_array($v['key'], $vocabLearned, true))
            ->map(fn ($v) => $v + ['streak' => $vocabStreaks[$v['key']] ?? 0])
            ->values();
    @endphp
    <div x-data="funkVocabQuiz(@js($open), {{ count($vocabLearned) }}, {{ $vocabTotal }}, {{ $streakToLearn }}, @js($answerUrl))" class="flex flex-col items-center gap-4 py-4">
        <div class="text-sm text-slate-600 dark:text-slate-400"><span x-text="learned"></span> / {{ $vocabTotal }} {{ $unit }} gelernt</div>
        <template x-if="current">
            <div class="w-full max-w-md flex flex-col items-center gap-4">
                <div class="flex items-center gap-3 text-xs">
                    <span class="rounded-full bg-slate-100 dark:bg-slate-700 px-2 py-0.5 text-slate-600 dark:text-slate-300">Serie <span x-text="current.streak"></span>/{{ $streakToLearn }}</span>
                    <label class="inline-flex items-center gap-1.5 text-slate-600 dark:text-slate-400 cursor-pointer">
                        <input type="checkbox" x-model="audio" class="rounded border-slate-300"> Vorlesen
                    </label>
                </div>
                <button type="button" @click="flip()" class="w-full min-h-[11rem] rounded-3xl bg-white dark:bg-slate-800 shadow-sm hover:shadow-md transition p-6 flex flex-col items-center justify-center text-center">
                    <span class="text-2xl font-semibold text-slate-800 dark:text-white" x-text="current.de"></span>
                    <span x-show="flipped" x-cloak class="mt-3 text-xl" style="color: var(--brand-secondary, #007A7A)" x-text="current.en"></span>
                    <span x-show="flipped && current.hint" x-cloak class="mt-1 text-xs text-slate-500 dark:text-slate-400" x-text="current.hint"></span>
                    <span x-show="! flipped" class="mt-3 text-xs text-slate-500 dark:text-slate-400">Tippen zum Umdrehen</span>
                </button>
                <div x-show="flipped" x-cloak class="grid grid-cols-2 gap-3 w-full">
                    <button type="button" @click="answer(false)" :disabled="busy" class="rounded-xl border border-rose-200 bg-rose-50 text-rose-700 py-2.5 text-sm font-semibold hover:bg-rose-100 disabled:opacity-50">Nicht gewusst</button>
                    <button type="button" @click="answer(true)" :disabled="busy" class="rounded-xl py-2.5 text-sm font-semibold text-white disabled:opacity-50" style="background-color: var(--brand-secondary, #007A7A)">Gewusst</button>
                </div>
            </div>
        </template>
        <template x-if="! current">
            <div class="rounded-2xl bg-white dark:bg-slate-800 shadow-sm p-6 text-center text-slate-700 dark:text-slate-200">Alle {{ $unit }} gelernt &ndash; stark!</div>
        </template>
    </div>
@else
    <div class="text-sm text-slate-600 dark:text-slate-400">{{ count($vocabLearned) }} / {{ $vocabTotal }} {{ $unit }} gelernt</div>
    @foreach ($vocabulary as $topic => $entries)
        <div>
            <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-2">{{ $topic }} &middot; {{ count($entries) }}</h3>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-2">
                @foreach ($entries as $entry)
                    @php $learned = in_array($entry['key'], $vocabLearned, true); @endphp
                    <div class="flex items-center gap-3 rounded-xl bg-white dark:bg-slate-800 shadow-sm px-4 py-2.5">
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-slate-800 dark:text-white">{{ $entry['de'] }}</div>
                            <div class="text-sm text-slate-600 dark:text-slate-400">{{ $entry['en'] }}@isset($entry['hint']) <span class="text-xs">&middot; {{ $entry['hint'] }}</span>@endisset</div>
                        </div>
                        <button type="button" x-data @click="funkSpeak(@js($entry['en']), 'en-GB')" aria-label="Vorlesen" class="w-8 h-8 rounded-full flex items-center justify-center text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700 shrink-0">
                            <x-icon name="speaker-wave" class="w-4 h-4" />
                        </button>
                        <x-icon name="check-circle" class="w-5 h-5 shrink-0 {{ $learned ? '' : 'text-slate-300 dark:text-slate-600' }}" :style="$learned ? 'color: var(--brand-secondary, #007A7A)' : null" />
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
@endif

@once
    <script nonce="{{ $cspNonce }}">
        window.funkVocabQuiz = function (items, learned, total, streakToLearn, url) {
            const queue = items.slice().sort(() => Math.random() - 0.5);
            let audio = true;
            try { audio = localStorage.getItem('funkVocabAudio') !== '0'; } catch (e) {}
            return {
                queue, learned, total, flipped: false, busy: false, audio,
                get current() { return this.queue[0] || null; },
                init() { this.$watch('audio', (value) => { try { localStorage.setItem('funkVocabAudio', value ? '1' : '0'); } catch (e) {} }); },
                flip() {
                    if (this.flipped) { return; }
                    this.flipped = true;
                    if (this.audio) { window.funkSpeak(this.current.en, 'en-GB'); }
                },
                async answer(known) {
                    this.busy = true;
                    try {
                        const response = await fetch(url, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                            body: JSON.stringify({ key: this.current.key, known }),
                        });
                        if (! response.ok) { throw new Error(response.status); }
                        const result = await response.json();
                        const item = this.queue.shift();
                        this.learned = result.learnedCount;
                        if (! result.learned) {
                            item.streak = result.streak;
                            this.queue.splice(Math.min(this.queue.length, known ? 5 : 2), 0, item);
                        }
                        this.flipped = false;
                    } finally {
                        this.busy = false;
                    }
                },
            };
        };
    </script>
@endonce
