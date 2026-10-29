{{-- Bug-Review (Du/Sie im Admin-Bereich gemischt): der restliche
     Admin-Bereich siezt durchgehend. --}}
<p class="text-slate-500 dark:text-slate-400 mb-6">
    Wählen Sie aus, welche Kurse Ihre Bootsschule anbietet. Abgewählte Kurse verschwinden für Ihre Lernenden
    vollständig aus der Kursübersicht &mdash; auch wenn bereits ein Zugang dafür besteht. Kurse, die der
    Plattform-Betreiber sitewide deaktiviert hat, erscheinen hier nicht und können nicht aktiviert werden.
    Verkaufen Sie Kurszugänge über Ihren eigenen Webshop, können Sie hier optional gleich je Kurs einen
    Kauf-Link hinterlegen &mdash; der ersetzt auf der Kursübersicht für Lernende ohne Zugang dann den
    Standardhinweis durch einen „Jetzt kaufen"-Button.
</p>

<div class="max-w-4xl bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6">
    <form method="POST" action="{{ route('admin.courses.update') }}" class="space-y-4">
        @csrf
        @method('PATCH')

        <div class="grid sm:grid-cols-2 gap-6 pb-5 border-b border-slate-100 dark:border-slate-700">
            <div>
                <label for="course_validity_days" class="block text-sm font-medium text-slate-700 dark:text-slate-200 mb-1">
                    Kurse X Tage für Teilnehmer verfügbar
                </label>
                <p class="text-xs text-slate-600 dark:text-slate-400 mb-2">
                    Nach Ablauf dieser Frist verliert ein Teilnehmer den Zugriff auf seinen Kurs und benötigt
                    einen Verlängerungs-Code.
                </p>
                <input type="number" id="course_validity_days" name="course_validity_days" min="1" max="3650"
                       value="{{ old('course_validity_days', $branding->course_validity_days) }}"
                       class="w-28 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                <x-input-error :messages="$errors->get('course_validity_days')" class="mt-1" />
            </div>

            <div>
                <label for="renewal_webshop_link" class="block text-sm font-medium text-slate-700 dark:text-slate-200 mb-1">
                    Verlängerungs-Codes
                </label>
                <p class="text-xs text-slate-600 dark:text-slate-400 mb-2">
                    Link zu Ihrem Webshop, wo Teilnehmer nach Ablauf einen Verlängerungs-Code kaufen können. Ohne
                    Link sehen Teilnehmer stattdessen den Hinweis, sich an Sie zu wenden.
                </p>
                <input type="url" id="renewal_webshop_link" name="renewal_webshop_link"
                       placeholder="https://ihr-webshop.de/verlaengerung (optional)"
                       value="{{ old('renewal_webshop_link', $branding->renewal_webshop_link) }}"
                       class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                <x-input-error :messages="$errors->get('renewal_webshop_link')" class="mt-1" />
            </div>
        </div>

        @php
            $isChecked = fn (string $courseId) => old('enabled')
                ? in_array($courseId, old('enabled', []), true)
                : ! $disabledCourseIds->contains($courseId);
        @endphp
        @forelse ($courses as $course)
            <div
                class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4 border-b border-slate-100 dark:border-slate-700 pb-4 last:border-0 last:pb-0"
                x-data="{ enabled: {{ $isChecked($course->id) ? 'true' : 'false' }} }"
            >
                <label class="flex items-center gap-3 sm:w-64 shrink-0">
                    <input type="checkbox" name="enabled[]" value="{{ $course->id }}"
                           x-model="enabled" @checked($isChecked($course->id))
                           class="rounded border-slate-300">
                    <span class="text-sm text-slate-700 dark:text-slate-200">{{ $course->name }}</span>
                </label>
                <div class="flex-1">
                    <input type="url" name="links[{{ $course->id }}]" placeholder="https://ihr-webshop.de/produkt/... (optional)"
                           value="{{ old('links.'.$course->id, $webshopLinks->get($course->id)) }}"
                           :class="{ 'opacity-50': ! enabled }"
                           class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                    <x-input-error :messages="$errors->get('links.'.$course->id)" class="mt-1" />
                </div>
            </div>
        @empty
            <p class="text-slate-500 dark:text-slate-400 text-sm">Aktuell sind keine Kurse sitewide verfügbar.</p>
        @endforelse

        @if ($courses->isNotEmpty())
            <button type="submit" class="px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                Änderungen speichern
            </button>
        @endif
    </form>
</div>
