<x-app-layout>
    <x-slot name="header">
        @include('funk._header', ['title' => 'Mündliche Prüfung', 'icon' => 'microphone'])
    </x-slot>

    @php
        $tabUrl = fn (array $query) => route('funk.oral.index', array_merge(['course' => $course, 'teil' => $catalog, 'modul' => $modul], $query));
    @endphp

    <div class="space-y-4">
        @include('funk._tabs', [
            'tabs' => collect($catalogs)->mapWithKeys(fn ($c) => [$c => route('funk.oral.index', ['course' => $course, 'teil' => $c])])->all(),
            'active' => $catalog,
        ])

            @include('funk._tabs', [
                'tabs' => ['Seefunktexte' => $tabUrl(['modul' => 'texte']), 'Vokabeln' => $tabUrl(['modul' => 'vokabeln'])],
                'active' => $modul === 'texte' ? 'Seefunktexte' : 'Vokabeln',
            ])

            @if ($modul === 'texte')
                @if (count($texts) === 0)
                    <x-funk-info>
                        In der Prüfung liest der Prüfer einen der amtlichen Seefunktexte vor; du schreibst mit und übersetzt ihn
                        (Deutsch &rarr; Englisch bzw. Englisch &rarr; Deutsch). Die amtlichen Texte werden hier ergänzt, sobald sie
                        vorliegen. Bis dahin hilft dir der Vokabeltrainer bei den Begriffen, die in den Texten vorkommen.
                    </x-funk-info>
                @else
                    <x-funk-info>
                        Es gibt {{ count($texts) }} amtliche Seefunktexte; in der Prüfung kommen davon zwei vor. Übe alle: anhören,
                        mitschreiben, übersetzen. {{ count($textsCompleted) }} von {{ count($texts) }} erledigt.
                    </x-funk-info>
                    <div class="grid grid-cols-3 sm:grid-cols-5 md:grid-cols-7 lg:grid-cols-9 gap-3">
                        @foreach ($texts as $text)
                            @php $done = in_array($text['key'], $textsCompleted, true); @endphp
                            <a href="{{ route('funk.oral.text', ['course' => $course, 'catalog' => $catalog, 'number' => $text['number']]) }}"
                               class="relative aspect-square rounded-2xl flex items-center justify-center text-lg font-semibold shadow-sm hover:shadow-md transition {{ $done ? 'text-white' : 'bg-white dark:bg-slate-800 text-slate-800 dark:text-white' }}"
                               @if ($done) style="background: linear-gradient(150deg, var(--brand-secondary, #007A7A), color-mix(in srgb, var(--brand-secondary, #007A7A) 60%, #001233));" @endif>
                                {{ $text['number'] }}
                                @if ($done)
                                    <x-icon name="check-circle" class="w-4 h-4 absolute top-2 right-2 text-white/80" />
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endif
            @else
                <x-funk-info>
                    Vokabeltrainer für den englischen Teil der SRC-Prüfung (Deutsch &rarr; Englisch). Karte umdrehen, dann ehrlich
                    einschätzen: Wusstest du sie? {{ $streakToLearn }}&times; in Folge gewusst &rarr; gilt als gelernt und fliegt aus dem Quiz.
                </x-funk-info>

                @include('funk._tabs', [
                    'tabs' => ['Quiz' => $tabUrl(['ansicht' => 'quiz']), 'Übersicht' => $tabUrl(['ansicht' => 'uebersicht'])],
                    'active' => $ansicht === 'quiz' ? 'Quiz' : 'Übersicht',
                ])

                @if ($ansicht === 'quiz')
                    @php
                        $open = collect($vocabulary)->flatten(1)
                            ->reject(fn ($v) => in_array($v['key'], $vocabLearned, true))
                            ->map(fn ($v) => $v + ['streak' => $vocabStreaks[$v['key']] ?? 0])
                            ->values();
                    @endphp
                    <div x-data="funkVocabQuiz(@js($open), {{ count($vocabLearned) }}, {{ $vocabTotal }}, {{ $streakToLearn }}, @js(route('funk.oral.vocab', ['course' => $course, 'catalog' => $catalog])))" class="flex flex-col items-center gap-4 py-4">
                        <div class="text-sm text-slate-600 dark:text-slate-400"><span x-text="learned"></span> / {{ $vocabTotal }} Vokabeln gelernt</div>
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
                                    <span x-show="! flipped" class="mt-3 text-xs text-slate-500 dark:text-slate-400">Tippen zum Umdrehen</span>
                                </button>
                                <div x-show="flipped" x-cloak class="grid grid-cols-2 gap-3 w-full">
                                    <button type="button" @click="answer(false)" :disabled="busy" class="rounded-xl border border-rose-200 bg-rose-50 text-rose-700 py-2.5 text-sm font-semibold hover:bg-rose-100 disabled:opacity-50">Nicht gewusst</button>
                                    <button type="button" @click="answer(true)" :disabled="busy" class="rounded-xl py-2.5 text-sm font-semibold text-white disabled:opacity-50" style="background-color: var(--brand-secondary, #007A7A)">Gewusst</button>
                                </div>
                            </div>
                        </template>
                        <template x-if="! current">
                            <div class="rounded-2xl bg-white dark:bg-slate-800 shadow-sm p-6 text-center text-slate-700 dark:text-slate-200">Alle Vokabeln gelernt &ndash; stark!</div>
                        </template>
                    </div>
                @else
                    <div class="text-sm text-slate-600 dark:text-slate-400">{{ count($vocabLearned) }} / {{ $vocabTotal }} Vokabeln gelernt</div>
                    @foreach ($vocabulary as $topic => $entries)
                        <div>
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-2">{{ $topic }} &middot; {{ count($entries) }}</h3>
                            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-2">
                                @foreach ($entries as $entry)
                                    @php $learned = in_array($entry['key'], $vocabLearned, true); @endphp
                                    <div class="flex items-center gap-3 rounded-xl bg-white dark:bg-slate-800 shadow-sm px-4 py-2.5">
                                        <div class="flex-1 min-w-0">
                                            <div class="text-sm font-medium text-slate-800 dark:text-white">{{ $entry['de'] }}</div>
                                            <div class="text-sm text-slate-600 dark:text-slate-400">{{ $entry['en'] }}</div>
                                        </div>
                                        <button type="button" x-data @click="funkSpeak(@js($entry['en']), 'en-GB')" aria-label="Englisch vorlesen" class="w-8 h-8 rounded-full flex items-center justify-center text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700 shrink-0">
                                            <x-icon name="speaker-wave" class="w-4 h-4" />
                                        </button>
                                        <x-icon name="check-circle" class="w-5 h-5 shrink-0 {{ $learned ? '' : 'text-slate-300 dark:text-slate-600' }}" :style="$learned ? 'color: var(--brand-secondary, #007A7A)' : null" />
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                @endif
            @endif
    </div>

    @include('funk._speech')
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
</x-app-layout>
