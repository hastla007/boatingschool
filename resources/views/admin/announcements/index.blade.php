<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Mitteilungen an Teilnehmer</h2>
    </x-slot>

    <a href="{{ route('admin.dashboard') }}" class="text-sm text-slate-600 dark:text-slate-400 hover:underline">&larr; Zurück zum Admin-Bereich</a>

    <div class="mt-4 grid lg:grid-cols-5 gap-4">
        <form method="POST" action="{{ route('admin.announcements.store') }}" class="lg:col-span-2 bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 space-y-3 self-start">
            @csrf
            <h3 class="font-medium text-slate-700 dark:text-slate-200">Neue Mitteilung</h3>
            <div>
                <x-input-label for="title" value="Titel" />
                <x-text-input id="title" name="title" type="text" maxlength="150" class="mt-1 block w-full" :value="old('title')" required placeholder="z. B. Theorieabend am Donnerstag fällt aus" />
                <x-input-error :messages="$errors->get('title')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="body" value="Text" />
                <textarea id="body" name="body" rows="5" maxlength="5000" required class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">{{ old('body') }}</textarea>
                <x-input-error :messages="$errors->get('body')" class="mt-1" />
            </div>
            <div class="grid sm:grid-cols-2 gap-3">
                <div>
                    <x-input-label for="course_id" value="Für" />
                    <select id="course_id" name="course_id" class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                        <option value="">Alle Teilnehmer</option>
                        @foreach ($courses as $course)
                            <option value="{{ $course->id }}" @selected(old('course_id') === $course->id)>{{ $course->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="visible_until" value="Anzeigen bis (optional)" />
                    <input id="visible_until" name="visible_until" type="date" value="{{ old('visible_until') }}" min="{{ now('Europe/Berlin')->toDateString() }}" class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                    <x-input-error :messages="$errors->get('visible_until')" class="mt-1" />
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                <input type="checkbox" name="send_mail" value="1" @checked(old('send_mail')) class="rounded border-slate-300">
                Zusätzlich per E-Mail an alle Empfänger mit aktivem Kurszugang
            </label>
            <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background-color: var(--brand-primary, #005FD7)">Veröffentlichen</button>
            <p class="text-xs text-slate-600 dark:text-slate-400">Die Mitteilung erscheint oben im Lern-Dashboard der Teilnehmer.</p>
        </form>

        <div class="lg:col-span-3 space-y-3">
            @forelse ($announcements as $announcement)
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 {{ $announcement->isVisible() ? '' : 'opacity-60' }}">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <h3 class="font-medium text-slate-800 dark:text-slate-100">{{ $announcement->title }}</h3>
                            <p class="text-xs text-slate-600 dark:text-slate-400">
                                {{ $announcement->created_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }} · {{ $announcement->author?->display_name ?? $announcement->author?->email ?? '—' }} ·
                                {{ $announcement->course?->name ?? 'alle Teilnehmer' }}
                                @if ($announcement->visible_until) · bis {{ $announcement->visible_until->format('d.m.Y') }} @endif
                                @if ($announcement->emailed_at) · per Mail an {{ $announcement->emailed_count }} @endif
                                @unless ($announcement->isVisible()) · abgelaufen @endunless
                            </p>
                        </div>
                        <form method="POST" action="{{ route('admin.announcements.destroy', $announcement) }}" x-data="{}"
                              @submit="if (! confirm('Mitteilung löschen?')) $event.preventDefault()">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs text-rose-600 hover:underline">Löschen</button>
                        </form>
                    </div>
                    <p class="mt-2 text-sm text-slate-700 dark:text-slate-200 whitespace-pre-line">{{ $announcement->body }}</p>
                </div>
            @empty
                <p class="text-sm text-slate-600 dark:text-slate-400">Noch keine Mitteilungen.</p>
            @endforelse
            {{ $announcements->links() }}
        </div>
    </div>
</x-app-layout>
