<x-superadmin-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Frage bearbeiten</h1>
    </x-slot>

    <div class="max-w-2xl space-y-4">
        <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-900/40 rounded-xl px-4 py-3 text-sm text-amber-800 dark:text-amber-200">
            Speichern erzeugt eine neue Fassung dieser Frage (Revision {{ $nextRevisionNo }}); die bisherige Fassung bleibt unveränderlich in der Historie erhalten.
        </div>

        @if ($pendingReview)
            {{-- Bug-Review F8: eine vom Content-Import angelegte Revision im
                 Status "review" erreichte Lerner bisher nie -- unten wird
                 weiterhin die aktuell veröffentlichte Fassung bearbeitet,
                 damit der Entwurf hier sichtbar bleibt und gezielt
                 veröffentlicht werden kann. --}}
            {{-- Bug-Review (Lösch-Rückfragen von CSP blockiert): onclick="return
                 confirm(...)" ist ein Inline-Event-Handler und wird von der CSP
                 (Bug-Review M9: script-src ohne 'unsafe-inline') blockiert --
                 die Bestätigung erschien nie, die Fassung wurde beim Klick
                 sofort und ohne Rückfrage veröffentlicht. Alpines @click-
                 Direktive greift nur innerhalb eines x-data-Teilbaums
                 (Bug-Review R4-2), das hier bisher komplett fehlte. --}}
            <div class="bg-sky-50 dark:bg-sky-900/20 border border-sky-100 dark:border-sky-900/40 rounded-xl px-4 py-3 text-sm text-sky-900 dark:text-sky-200 space-y-2" x-data="{}">
                <p class="font-medium">Es liegt eine noch nicht veröffentlichte Fassung (Revision {{ $pendingReview->revision_no }}) vor, z. B. aus einem erneuten Content-Import:</p>
                <p class="italic">{{ $pendingReview->question_text }}</p>
                @php($publishedRevision = $question->publishedRevision())
                @if ($publishedRevision && $publishedRevision->revision_no > $pendingReview->revision_no)
                    <p class="font-medium text-amber-800 dark:text-amber-300">
                        Achtung: Die veröffentlichte Fassung (Revision {{ $publishedRevision->revision_no }}) ist neuer und wurde im Editor geändert.
                        Diese Fassung zu veröffentlichen ersetzt diese Änderungen. Ist die Import-Änderung überholt, verwirf sie stattdessen.
                    </p>
                @endif
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('superadmin.questions.revisions.publish', $pendingReview) }}">
                        @csrf
                        <button type="submit" class="px-3 py-1.5 rounded-lg text-white text-xs font-medium hover:opacity-90 transition" style="background-color: #005FD7" @click="if (! confirm('Diese Fassung jetzt veröffentlichen?')) $event.preventDefault()">
                            Diese Fassung veröffentlichen
                        </button>
                    </form>
                    <form method="POST" action="{{ route('superadmin.questions.revisions.discard', $pendingReview) }}">
                        @csrf
                        <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-medium border border-sky-300 dark:border-sky-700 hover:bg-sky-100 dark:hover:bg-sky-900/40 transition" @click="if (! confirm('Diese wartende Fassung verwerfen?')) $event.preventDefault()">
                            Verwerfen
                        </button>
                    </form>
                </div>
            </div>
        @endif

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6">
            <form method="POST" action="{{ route('superadmin.questions.update', $question) }}" class="space-y-5">
                @csrf
                @method('PATCH')
                @include('superadmin.questions._form')
                <button type="submit" class="px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: #005FD7">
                    Neue Fassung speichern
                </button>
            </form>
        </div>
    </div>
</x-superadmin-layout>
