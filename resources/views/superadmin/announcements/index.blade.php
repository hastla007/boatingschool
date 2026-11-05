<x-superadmin-layout>
    <x-slot name="header">
        <div>
            <div class="text-xs text-slate-600 dark:text-slate-400"><a href="{{ route('superadmin.tenants.index') }}" class="hover:underline">Bootsschulen</a></div>
            <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Mitteilung an alle Schulen</h1>
        </div>
    </x-slot>

    <div class="grid lg:grid-cols-5 gap-4">
        <form method="POST" action="{{ route('superadmin.announcements.store') }}" class="lg:col-span-2 bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 space-y-3 self-start"
              x-data="{ target: @js(old('target', 'all')) }">
            @csrf
            <h3 class="font-medium text-slate-700 dark:text-slate-200">Neue Mitteilung</h3>
            <div>
                <x-input-label for="title" value="Titel" />
                <x-text-input id="title" name="title" type="text" maxlength="150" class="mt-1 block w-full" :value="old('title')" required placeholder="z. B. Wartung am Sonntag, 6–8 Uhr" />
                <x-input-error :messages="$errors->get('title')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="body" value="Text" />
                <textarea id="body" name="body" rows="6" maxlength="5000" required class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">{{ old('body') }}</textarea>
                <x-input-error :messages="$errors->get('body')" class="mt-1" />
            </div>

            <fieldset>
                <legend class="text-sm font-medium text-slate-700 dark:text-slate-200">An</legend>
                <label class="mt-1 flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                    <input type="radio" name="target" value="all" x-model="target" class="border-slate-300"> Alle Bootsschulen
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                    <input type="radio" name="target" value="selected" x-model="target" class="border-slate-300"> Ausgewählte Bootsschulen
                </label>
                <div x-show="target === 'selected'" @if (old('target', 'all') !== 'selected') style="display: none" @endif class="mt-2 max-h-48 overflow-y-auto rounded-lg border border-slate-200 dark:border-slate-700 p-2 space-y-1">
                    @foreach ($tenants as $tenant)
                        <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                            <input type="checkbox" name="tenant_ids[]" value="{{ $tenant->id }}" @checked(in_array($tenant->id, old('tenant_ids', []), true)) class="rounded border-slate-300">
                            {{ $tenant->name }}
                            @if ($tenant->status !== 'active')
                                <span class="text-xs text-slate-500 dark:text-slate-400">({{ \App\Support\StatusLabels::tenantStatus($tenant->status) }})</span>
                            @endif
                        </label>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('tenant_ids')" class="mt-1" />
            </fieldset>

            <div class="grid sm:grid-cols-2 gap-3">
                <div>
                    <x-input-label for="audience" value="Sichtbar für" />
                    <select id="audience" name="audience" class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                        <option value="team" @selected(old('audience', 'team') === 'team')>Nur Schul-Teams</option>
                        <option value="all" @selected(old('audience') === 'all')>Teams und Lernende</option>
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
                Zusätzlich per E-Mail an Inhaber und Admins (aktive Schulen)
            </label>
            <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90">Veröffentlichen</button>
            <p class="text-xs text-slate-600 dark:text-slate-400">Die Mitteilung erscheint oben im Admin-Bereich der Schulen, bei „und Lernende“ auch im Lern-Dashboard. Lernende bekommen keine Mail.</p>
        </form>

        <div class="lg:col-span-3 space-y-3">
            @forelse ($announcements as $announcement)
                @php
                    $names = $announcement->tenant_ids === null ? null : $tenants->whereIn('id', $announcement->tenant_ids)->pluck('name');
                @endphp
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 {{ $announcement->isVisible() ? '' : 'opacity-60' }}">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h3 class="font-medium text-slate-800 dark:text-slate-100">{{ $announcement->title }}</h3>
                            <p class="text-xs text-slate-600 dark:text-slate-400">
                                {{ $announcement->created_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }} · {{ $announcement->author?->display_name ?? $announcement->author?->email ?? '—' }} ·
                                {{ $names === null ? 'alle Schulen' : $names->implode(', ') }} ·
                                {{ $announcement->audience === 'all' ? 'Teams und Lernende' : 'nur Teams' }}
                                @if ($announcement->visible_until) · bis {{ $announcement->visible_until->format('d.m.Y') }} @endif
                                @if ($announcement->emailed_at) · per Mail an {{ $announcement->emailed_count }} @endif
                                @unless ($announcement->isVisible()) · abgelaufen @endunless
                            </p>
                        </div>
                        <form method="POST" action="{{ route('superadmin.announcements.destroy', $announcement) }}" x-data="{}"
                              @submit="if (! confirm('Mitteilung löschen? Sie verschwindet bei allen Schulen.')) $event.preventDefault()">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs text-rose-600 hover:underline">Löschen</button>
                        </form>
                    </div>
                    <p class="mt-2 text-sm text-slate-700 dark:text-slate-200 whitespace-pre-line">{{ $announcement->body }}</p>
                </div>
            @empty
                <p class="text-sm text-slate-600 dark:text-slate-400">Noch keine Mitteilungen an die Schulen.</p>
            @endforelse
            {{ $announcements->links() }}
        </div>
    </div>
</x-superadmin-layout>
