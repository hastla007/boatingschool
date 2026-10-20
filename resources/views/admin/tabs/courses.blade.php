<p class="text-slate-500 mb-6">
    Wähle aus, welche Kurse deine Bootsschule anbietet. Abgewählte Kurse verschwinden für deine Lernenden
    vollständig aus der Kursübersicht &mdash; auch wenn bereits ein Zugang dafür besteht. Kurse, die der
    Plattform-Betreiber sitewide deaktiviert hat, erscheinen hier nicht und können nicht aktiviert werden.
    Verkaufst du Kurszugänge über deinen eigenen Webshop, kannst du hier optional gleich je Kurs einen
    Kauf-Link hinterlegen &mdash; der ersetzt auf der Kursübersicht für Lernende ohne Zugang dann den
    Standardhinweis durch einen „Jetzt kaufen"-Button.
</p>

<div class="max-w-4xl bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6">
    <form method="POST" action="{{ route('admin.courses.update') }}" class="space-y-4">
        @csrf
        @method('PATCH')

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
                    <input type="url" name="links[{{ $course->id }}]" placeholder="https://dein-webshop.de/produkt/... (optional)"
                           value="{{ old('links.'.$course->id, $webshopLinks->get($course->id)) }}"
                           :class="{ 'opacity-50': ! enabled }"
                           class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                    <x-input-error :messages="$errors->get('links.'.$course->id)" class="mt-1" />
                </div>
            </div>
        @empty
            <p class="text-slate-500 text-sm">Aktuell sind keine Kurse sitewide verfügbar.</p>
        @endforelse

        @if ($courses->isNotEmpty())
            <button type="submit" class="px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                Änderungen speichern
            </button>
        @endif
    </form>
</div>
