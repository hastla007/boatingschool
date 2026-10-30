<x-superadmin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-xs text-slate-600 dark:text-slate-400"><a href="{{ route('superadmin.modules.index') }}" class="hover:underline">Module</a></div>
                <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">{{ $module->name }} &middot; Fragen</h1>
            </div>
            <a href="{{ route('superadmin.questions.create', $module) }}" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90 transition">+ Neue Frage</a>
        </div>
    </x-slot>

    {{-- Bug-Review (Lösch-Rückfragen von CSP blockiert): onclick="return
         confirm(...)" ist ein Inline-Event-Handler und wird von der CSP
         (Bug-Review M9: script-src ohne 'unsafe-inline') blockiert -- die
         Bestätigung erschien nie, die Fassung wurde beim Klick sofort und
         ohne Rückfrage veröffentlicht. Alpines @click-Direktive greift nur
         innerhalb eines x-data-Teilbaums (Bug-Review R4-2), das hier bisher
         komplett fehlte. --}}
    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden" x-data="{}">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 dark:bg-slate-700 text-slate-500 dark:text-slate-300 text-left">
                <tr>
                    <th class="px-4 py-2">Frage</th>
                    <th class="px-4 py-2">Thema</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($questions as $question)
                    @php($revision = $question->revisions->firstWhere('editorial_status', 'published') ?? $question->revisions->first())
                    {{-- Bug-Review (AA9: Wartende Import-Änderung
                         verschwindet): eine Revision im Status "review"
                         (siehe ImportContentQuestions) war früher dank
                         aufsteigender revision_no immer die zuletzt
                         angelegte, also die erste in dieser absteigend
                         sortierten Liste -- hat der Superadmin die Frage
                         über den Editor gespeichert, während diese Review-
                         Revision noch wartete, entstand aber eine noch
                         neuere, bereits veröffentlichte Revision (siehe
                         QuestionController::update(), T3), und die wartende
                         Review-Revision verschwand dadurch unbemerkt aus
                         dieser Liste. Gesucht wird deshalb gezielt nach dem
                         Status, nicht nach der Position. --}}
                    @php($pendingReview = $question->revisions->where('editorial_status', 'review')->sortByDesc('revision_no')->first())
                    <tr class="border-t border-slate-100 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700/50 {{ $question->active ? '' : 'opacity-50' }}">
                        <td class="px-4 py-2 max-w-md">
                            <a href="{{ route('superadmin.questions.edit', $question) }}" class="text-slate-700 dark:text-slate-200 hover:underline line-clamp-2">{{ $revision?->question_text ?? '(keine Fassung)' }}</a>
                        </td>
                        <td class="px-4 py-2 text-slate-500 dark:text-slate-400">{{ $revision?->smartmodus_kategorie ?: $revision?->topic }}</td>
                        <td class="px-4 py-2 text-slate-500 dark:text-slate-400">
                            {{ $question->active ? 'aktiv' : 'deaktiviert' }}
                            @if ($pendingReview)
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[11px] font-medium bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300 ms-1">Review ausstehend</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            @if ($pendingReview)
                                <form method="POST" action="{{ route('superadmin.questions.revisions.publish', $pendingReview) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="text-xs hover:underline me-2" style="color: #005FD7" @click="if (! confirm('Die Review-Fassung dieser Frage jetzt veröffentlichen?')) $event.preventDefault()">Veröffentlichen</button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('superadmin.questions.toggle-active', $question) }}" class="inline">
                                @csrf
                                <button type="submit" class="text-xs text-slate-500 dark:text-slate-400 hover:underline">{{ $question->active ? 'Deaktivieren' : 'Aktivieren' }}</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>
</x-superadmin-layout>
