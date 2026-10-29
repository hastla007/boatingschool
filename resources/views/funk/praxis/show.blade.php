<x-app-layout>
    <x-slot name="header">
        @include('funk._header', ['title' => 'Praxis-Prüfungssimulation', 'icon' => 'signal'])
    </x-slot>

    @php
        $fieldIcons = ['rank' => 'flag', 'dsc' => 'signal', 'circle' => 'map-pin', 'radio' => 'phone', 'message' => 'microphone'];
        $speechLang = $catalog === 'SRC' ? 'en-GB' : 'de-DE';
    @endphp

    <div class="space-y-4">
        @include('funk._tabs', [
            'tabs' => collect($catalogs)->mapWithKeys(fn ($c) => [$c => route('funk.praxis.index', ['course' => $course, 'teil' => $c])])->all(),
            'active' => $catalog,
        ])

        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('funk.praxis.index', ['course' => $course, 'teil' => $catalog]) }}" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50">
                <x-icon name="chevron-right" class="w-4 h-4 rotate-180" /> Zur Übersicht
            </a>
            <h2 class="font-semibold text-lg text-slate-800 dark:text-white">Aufgabe {{ $task['number'] }}</h2>
            <span class="text-xs rounded-full bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 px-2 py-0.5">{{ $task['number'] }} / {{ $total }}</span>
            <form method="POST" action="{{ route('funk.completed', ['course' => $course, 'type' => 'praxis', 'catalog' => $catalog, 'number' => $task['number']]) }}" class="sm:ml-auto">
                @csrf
                <button type="submit" class="inline-flex items-center gap-1.5 rounded-full border px-4 py-1.5 text-sm font-medium transition {{ $completed ? 'text-white border-transparent' : 'bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 border-slate-300 dark:border-slate-600 hover:bg-slate-50' }}"
                        @if ($completed) style="background-color: var(--brand-secondary, #007A7A)" @endif>
                    <x-icon name="check-circle" class="w-4 h-4" /> {{ $completed ? 'Erledigt' : 'Als erledigt markieren' }}
                </button>
            </form>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 space-y-3">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-1">Situation</div>
                <p class="text-slate-800 dark:text-slate-100 leading-relaxed">{{ $task['situation_de'] }}</p>
            </div>
            @if ($task['situation_en'])
                <div class="pt-3 border-t border-slate-100 dark:border-slate-700">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-1">English</div>
                    <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">{{ $task['situation_en'] }}</p>
                </div>
            @endif
        </div>

        <form method="POST" action="{{ route('funk.praxis.evaluate', ['course' => $course, 'catalog' => $catalog, 'number' => $task['number']]) }}" class="space-y-4"
              x-data="{ filled: {{ trim(implode('', $answers)) !== '' ? 'true' : 'false' }} }" @input="filled = [...$el.querySelectorAll('[data-answer]')].some(f => f.value.trim() !== '')">
            @csrf
            @foreach ($task['fields'] as $field)
                @php $results = $evaluation['fields'][$field['name']] ?? null; @endphp
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
                    <div class="flex items-center justify-between gap-3 mb-2">
                        <label for="answer-{{ $field['name'] }}" class="font-medium text-slate-800 dark:text-white flex items-center gap-2">
                            <x-icon :name="$fieldIcons[$field['name']]" class="w-4 h-4 text-slate-500 dark:text-slate-400" /> {{ $field['label'] }}
                        </label>
                        @if ($field['multiline'])
                            <div x-data="funkDictation('{{ $speechLang }}')" x-show="supported" x-cloak>
                                <button type="button" @click="toggle(document.getElementById('answer-{{ $field['name'] }}'))" class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm transition"
                                        :class="active ? 'bg-rose-50 border-rose-300 text-rose-700' : 'border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200 hover:bg-slate-50'">
                                    <x-icon name="microphone" class="w-4 h-4" /> <span x-text="active ? 'Aufnahme beenden' : 'Einsprechen'"></span>
                                </button>
                            </div>
                        @endif
                    </div>
                    @if ($field['multiline'])
                        <textarea id="answer-{{ $field['name'] }}" name="answers[{{ $field['name'] }}]" rows="6" data-answer
                                  placeholder="{{ $field['placeholder'] }}"
                                  class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm font-mono">{{ old('answers.'.$field['name'], $answers[$field['name']] ?? '') }}</textarea>
                    @else
                        <input id="answer-{{ $field['name'] }}" type="text" name="answers[{{ $field['name'] }}]" data-answer
                               value="{{ old('answers.'.$field['name'], $answers[$field['name']] ?? '') }}" placeholder="{{ $field['placeholder'] }}"
                               class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                    @endif

                    @if ($results)
                        <ul class="mt-3 grid sm:grid-cols-2 gap-x-4 gap-y-1">
                            @foreach ($results as $check)
                                <li class="flex items-start gap-1.5 text-sm {{ $check['ok'] ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                                    <x-icon :name="$check['ok'] ? 'check-circle' : 'x-circle'" class="w-4 h-4 shrink-0 mt-0.5" /> {{ $check['label'] }}
                                </li>
                            @endforeach
                        </ul>
                        <div class="mt-3 rounded-lg bg-slate-50 dark:bg-slate-900/40 border border-slate-200 dark:border-slate-700 p-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-1">Musterlösung</div>
                            <div class="text-sm text-slate-700 dark:text-slate-200 whitespace-pre-line {{ $field['multiline'] ? 'font-mono' : '' }}">{{ $field['solution'] }}</div>
                        </div>
                    @endif
                </div>
            @endforeach

            @error('answers')
                <p class="text-sm text-rose-600">{{ $message }}</p>
            @enderror

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" :disabled="! filled" class="inline-flex items-center gap-1.5 rounded-lg px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50 disabled:cursor-not-allowed"
                        style="background-color: var(--brand-secondary, #007A7A)">
                    <x-icon name="check-circle" class="w-4 h-4" /> Auswerten
                </button>
                @if ($evaluation)
                    <span class="text-sm font-medium {{ $evaluation['passed'] === $evaluation['total'] ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-700 dark:text-slate-200' }}">
                        {{ $evaluation['passed'] }} von {{ $evaluation['total'] }} Prüfpunkten erfüllt
                    </span>
                @else
                    <span class="text-xs text-slate-600 dark:text-slate-400">Trag zuerst etwas ein. Die Auswertung prüft die vorgeschriebenen Bestandteile und zeigt dir die Musterlösung.</span>
                @endif
            </div>
        </form>

        <div class="grid grid-cols-2 gap-3">
            @if ($previous)
                <a href="{{ route('funk.praxis.show', ['course' => $course, 'catalog' => $catalog, 'number' => $previous]) }}" class="text-center rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 py-2.5 text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-50">&larr; Vorherige</a>
            @else
                <span class="text-center rounded-xl border border-slate-100 dark:border-slate-800 py-2.5 text-sm text-slate-500 dark:text-slate-400">&larr; Vorherige</span>
            @endif
            @if ($next)
                <a href="{{ route('funk.praxis.show', ['course' => $course, 'catalog' => $catalog, 'number' => $next]) }}" class="text-center rounded-xl py-2.5 text-sm font-semibold text-white hover:opacity-95" style="background-color: var(--brand-secondary, #007A7A)">Nächste &rarr;</a>
            @else
                <a href="{{ route('funk.praxis.index', ['course' => $course, 'teil' => $catalog]) }}" class="text-center rounded-xl py-2.5 text-sm font-semibold text-white hover:opacity-95" style="background-color: var(--brand-secondary, #007A7A)">Zur Übersicht</a>
            @endif
        </div>
    </div>

    @include('funk._speech')
</x-app-layout>
