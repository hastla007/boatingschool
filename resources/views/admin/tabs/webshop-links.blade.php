<p class="text-slate-500 mb-6">
    Verkaufst du Kurszugänge über deinen eigenen Webshop, kannst du hier je Kurs einen Kauf-Link hinterlegen.
    Ist ein Link gesetzt, zeigt die Kursübersicht für Schüler ohne Zugang statt „Kein Zugang &mdash; bitte bei
    deiner Bootsschule anfragen" einen „Jetzt kaufen"-Button, der auf diesen Link führt. Ohne hinterlegten
    Link bleibt der bisherige Hinweistext bestehen.
</p>

<div class="max-w-2xl bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6">
    <form method="POST" action="{{ route('admin.webshop-links.update') }}" class="space-y-4">
        @csrf
        @method('PATCH')

        @foreach ($courses as $course)
            @php($courseDisabled = $disabledCourseIds->contains($course->id))
            {{-- Bei abgewähltem Kurs (siehe Kursauswahl) wird das Feld nur
                 optisch ausgegraut -- der Link bleibt editierbar und wird
                 mitgespeichert, damit die Bootsschule ihn schon für später
                 hinterlegen kann, bevor sie den Kurs wieder aktiviert. --}}
            <div @class(['opacity-50' => $courseDisabled])>
                <label class="block text-sm font-medium text-slate-600 dark:text-slate-300 mb-1">
                    {{ $course->name }}
                    @if ($courseDisabled)
                        <span class="text-xs font-normal text-slate-400">(Kurs aktuell abgewählt)</span>
                    @endif
                </label>
                <input type="url" name="links[{{ $course->id }}]" placeholder="https://dein-webshop.de/produkt/..."
                       value="{{ old('links.'.$course->id, $webshopLinks->get($course->id)) }}"
                       class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                <x-input-error :messages="$errors->get('links.'.$course->id)" class="mt-1" />
            </div>
        @endforeach

        <button type="submit" class="px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
            Änderungen speichern
        </button>
    </form>
</div>
