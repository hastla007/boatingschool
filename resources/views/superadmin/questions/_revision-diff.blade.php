{{-- Vergleich veröffentlichte Fassung ($from, kann fehlen) mit wartender Fassung ($to). --}}
@php
    $fields = [
        'question_text' => 'Fragetext',
        'feedback_correct' => 'Erklärung (richtig)',
        'feedback_incorrect' => 'Erklärung (falsch)',
        'topic' => 'Thema',
        'subtopic' => 'Unterthema',
        'smartmodus_kategorie' => 'Smartmodus-Kategorie',
    ];
    $changed = collect($fields)->filter(fn ($label, $field) => ! $from || (string) $from->{$field} !== (string) $to->{$field});
    $fromAnswers = $from ? $from->answers->keyBy('answer_key') : collect();
    $toAnswers = $to->answers->keyBy('answer_key');
    $answerKeys = $fromAnswers->keys()->merge($toAnswers->keys())->unique()->sort()->values();
    $answerChanged = $answerKeys->filter(fn ($key) => ! $from
        || (string) optional($fromAnswers->get($key))->answer_text !== (string) optional($toAnswers->get($key))->answer_text
        || (bool) optional($fromAnswers->get($key))->is_correct !== (bool) optional($toAnswers->get($key))->is_correct);
@endphp
<div class="revision-diff rounded-lg bg-white dark:bg-slate-800 border border-sky-100 dark:border-sky-900/40 divide-y divide-slate-100 dark:divide-slate-700 text-slate-700 dark:text-slate-200">
    <div class="px-3 py-2 text-xs text-slate-600 dark:text-slate-400 flex flex-wrap gap-x-4 gap-y-1">
        @if ($from)
            <span>Vergleich mit der veröffentlichten Revision {{ $from->revision_no }}</span>
            <span><del>entfernt</del> &middot; <ins>neu</ins></span>
        @else
            <span>Noch keine veröffentlichte Fassung: alles neu.</span>
        @endif
    </div>
    @foreach ($changed as $field => $label)
        <div class="px-3 py-2">
            <div class="text-xs font-medium text-slate-600 dark:text-slate-400 mb-0.5">{{ $label }}</div>
            <div class="whitespace-pre-line">{!! App\Support\TextDiff::html($from?->{$field}, $to->{$field}) !!}</div>
        </div>
    @endforeach
    @foreach ($answerChanged as $key)
        @php($old = $fromAnswers->get($key))
        @php($new = $toAnswers->get($key))
        <div class="px-3 py-2">
            <div class="text-xs font-medium text-slate-600 dark:text-slate-400 mb-0.5">
                Antwort {{ $key }}
                @if ($old && $new && $old->is_correct !== $new->is_correct)
                    <span class="ml-1 text-amber-700 dark:text-amber-300">({{ $old->is_correct ? 'richtig' : 'falsch' }} → {{ $new->is_correct ? 'richtig' : 'falsch' }})</span>
                @elseif ($new?->is_correct)
                    <span class="ml-1 text-emerald-700 dark:text-emerald-300">(richtig)</span>
                @endif
                @if (! $new)
                    <span class="ml-1 text-rose-700 dark:text-rose-300">(entfällt)</span>
                @endif
            </div>
            <div>{!! App\Support\TextDiff::html($old?->answer_text, $new?->answer_text) !!}</div>
        </div>
    @endforeach
    @if ($from && $changed->isEmpty() && $answerChanged->isEmpty())
        <div class="px-3 py-2 text-sm">Inhaltlich keine Unterschiede (nur Metadaten wie Quelle oder Stand).</div>
    @elseif ($from)
        @php($same = collect($fields)->except($changed->keys())->values())
        @if ($same->isNotEmpty() || $answerKeys->count() > $answerChanged->count())
            <div class="px-3 py-2 text-xs text-slate-600 dark:text-slate-400">
                Unverändert: {{ $same->merge($answerKeys->diff($answerChanged)->map(fn ($k) => 'Antwort '.$k))->implode(', ') }}
            </div>
        @endif
    @endif
</div>
