<x-superadmin-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Protokoll</h1>
    </x-slot>

    @php($input = 'mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm')
    <div class="space-y-4">
        <form method="GET" action="{{ route('superadmin.audit.index') }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 grid grid-cols-2 md:grid-cols-6 gap-3 items-end">
            <div class="col-span-2 md:col-span-1">
                <x-input-label for="tenant" value="Bootsschule" />
                <select id="tenant" name="tenant" class="{{ $input }}">
                    <option value="">Alle</option>
                    <option value="platform" @selected($filters['tenant'] === 'platform')>Nur Plattform</option>
                    @foreach ($tenants as $tenant)
                        <option value="{{ $tenant->id }}" @selected($filters['tenant'] === $tenant->id)>{{ $tenant->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <x-input-label for="actor" value="Person (E-Mail)" />
                <input id="actor" name="actor" type="text" value="{{ $filters['actor'] }}" class="{{ $input }}">
            </div>
            <div>
                <x-input-label for="action" value="Aktion" />
                <input id="action" name="action" type="text" value="{{ $filters['action'] }}" placeholder="z. B. coupon" class="{{ $input }}">
            </div>
            <div>
                <x-input-label for="from" value="Von" />
                <input id="from" name="from" type="date" value="{{ $filters['from'] }}" class="{{ $input }}">
            </div>
            <div>
                <x-input-label for="to" value="Bis" />
                <input id="to" name="to" type="date" value="{{ $filters['to'] }}" class="{{ $input }}">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90">Filtern</button>
                <a href="{{ route('superadmin.audit.index') }}" class="px-3 py-2 rounded-lg bg-slate-100 dark:bg-slate-700 text-sm">Zurücksetzen</a>
            </div>
            @if ($filters['entity'])
                <input type="hidden" name="entity" value="{{ $filters['entity'] }}">
                <p class="col-span-2 md:col-span-6 text-xs text-slate-600 dark:text-slate-400">Nur Einträge zu Objekt {{ $filters['entity'] }}</p>
            @endif
        </form>

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm divide-y divide-slate-100 dark:divide-slate-700">
            @forelse ($entries as $entry)
                @php($actor = $actors->get($entry->actor_user_id))
                <details class="px-4 py-2 text-sm group">
                    <summary class="cursor-pointer flex flex-wrap items-center gap-x-3 gap-y-1 list-none">
                        <span class="text-xs text-slate-600 dark:text-slate-400 w-32 shrink-0">{{ $entry->created_at?->timezone('Europe/Berlin')->format('d.m.Y H:i:s') }}</span>
                        <span class="font-mono text-xs text-slate-800 dark:text-slate-100">{{ $entry->action }}</span>
                        <span class="text-xs text-slate-600 dark:text-slate-300">{{ $actor ? ($actor->display_name ?: $actor->email) : ($entry->actor_user_id ? 'gelöschtes Konto' : 'System') }}</span>
                        <span class="text-xs rounded-full px-2 py-0.5 bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200">{{ $entry->tenant_id ? ($tenantNames[$entry->tenant_id] ?? 'gelöschte Schule') : 'Plattform' }}</span>
                    </summary>
                    <div class="mt-2 grid md:grid-cols-2 gap-3 text-xs">
                        <div class="md:col-span-2 text-slate-600 dark:text-slate-400">
                            Objekt: {{ $entry->entity_type }}
                            @if ($entry->entity_id)
                                · <a href="{{ route('superadmin.audit.index', ['entity' => $entry->entity_id]) }}" class="font-mono hover:underline">{{ $entry->entity_id }}</a>
                            @endif
                            @if ($actor)
                                · <a href="{{ route('superadmin.users.show', $actor->id) }}" class="hover:underline">{{ $actor->email }}</a>
                            @endif
                        </div>
                        @foreach (['before_data' => 'Vorher', 'after_data' => 'Nachher'] as $field => $label)
                            <div>
                                <div class="font-medium text-slate-600 dark:text-slate-400 mb-1">{{ $label }}</div>
                                <pre class="whitespace-pre-wrap break-words rounded-lg bg-slate-50 dark:bg-slate-900 p-2 text-slate-700 dark:text-slate-200 max-h-64 overflow-auto">{{ $entry->{$field} !== null ? json_encode($entry->{$field}, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '—' }}</pre>
                            </div>
                        @endforeach
                    </div>
                </details>
            @empty
                <p class="px-4 py-6 text-sm text-slate-600 dark:text-slate-400">Keine Einträge gefunden.</p>
            @endforelse
        </div>

        {{ $entries->links() }}
    </div>
</x-superadmin-layout>
