<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Prüfungssimulation</h2>
            <div class="text-sm text-slate-500 flex items-center gap-1.5">
                <x-icon name="clipboard-document-check" class="w-4 h-4 text-slate-400" />
                Frage {{ $current->position }} von {{ $total }}
                @if (! is_null($remainingSeconds))
                    <span class="text-slate-300">&middot;</span>
                    <x-icon name="clock" class="w-4 h-4 text-slate-400" />
                    <span id="exam-timer" data-remaining="{{ $remainingSeconds }}">{{ gmdate('i:s', $remainingSeconds) }}</span>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="max-w-2xl mx-auto bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6">
        <div class="w-full bg-slate-100 dark:bg-slate-700 rounded-full h-1.5 mb-6">
            <div class="h-1.5 rounded-full transition-all" style="width: {{ $total ? ($answered / $total * 100) : 0 }}%; background-color: var(--brand-primary, #005FD7)"></div>
        </div>

        <h3 class="text-lg font-medium text-slate-800 dark:text-white mb-4">{{ $current->revision->question_text }}</h3>

        <form method="POST" action="{{ route('exam.answer', $examSession) }}" x-data="{ selected: null }">
            @csrf
            <input type="hidden" name="position" value="{{ $current->position }}">

            <div class="space-y-2">
                @foreach ($current->revision->answers as $answer)
                    <label class="flex items-center gap-3 border border-slate-200 dark:border-slate-600 hover:border-slate-400 rounded-xl px-4 py-3 cursor-pointer transition">
                        <input type="radio" name="answer_id" value="{{ $answer->id }}" x-model="selected" class="shrink-0">
                        <span class="text-slate-700 dark:text-slate-200">{{ $answer->answer_text }}</span>
                    </label>
                @endforeach
            </div>

            <div class="flex justify-end mt-6">
                <button type="submit" x-bind:disabled="!selected" class="inline-flex items-center gap-1.5 px-5 py-2 rounded-lg text-white text-sm font-medium disabled:opacity-40 hover:opacity-90 transition"
                        style="background-color: var(--brand-primary, #005FD7)">
                    {{ $current->position === $total ? 'Abschließen' : 'Weiter' }} <x-icon name="arrow-right" class="w-4 h-4" />
                </button>
            </div>
        </form>
    </div>

    @if (! is_null($remainingSeconds))
        <script>
            (function () {
                const el = document.getElementById('exam-timer');
                let remaining = parseInt(el.dataset.remaining, 10);
                setInterval(function () {
                    remaining = Math.max(0, remaining - 1);
                    const m = String(Math.floor(remaining / 60)).padStart(2, '0');
                    const s = String(remaining % 60).padStart(2, '0');
                    el.textContent = m + ':' + s;
                    if (remaining <= 0) window.location.reload();
                }, 1000);
            })();
        </script>
    @endif
</x-app-layout>
