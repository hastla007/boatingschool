<p class="text-slate-500 mb-6">
    Wähle aus, welche Kurse deine Bootsschule anbietet. Abgewählte Kurse verschwinden für deine Lernenden
    vollständig aus der Kursübersicht &mdash; auch wenn bereits ein Zugang dafür besteht. Kurse, die der
    Plattform-Betreiber sitewide deaktiviert hat, erscheinen hier nicht und können nicht aktiviert werden.
</p>

<div class="max-w-2xl bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6">
    <form method="POST" action="{{ route('admin.courses.update') }}" class="space-y-3">
        @csrf
        @method('PATCH')

        @php
            $isChecked = fn (string $courseId) => old('enabled')
                ? in_array($courseId, old('enabled', []), true)
                : ! $disabledCourseIds->contains($courseId);
        @endphp
        @forelse ($courses as $course)
            <label class="flex items-center gap-3 border-b border-slate-100 dark:border-slate-700 pb-3 last:border-0 last:pb-0">
                <input type="checkbox" name="enabled[]" value="{{ $course->id }}"
                       @checked($isChecked($course->id))
                       class="rounded border-slate-300">
                <span class="text-sm text-slate-700 dark:text-slate-200">{{ $course->name }}</span>
            </label>
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
