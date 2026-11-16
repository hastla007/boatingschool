<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Lerngruppe: {{ $group->name }}</h2>
    </x-slot>

    @php($revoked = $group->status === 'revoked')
    <a href="{{ route('admin.dashboard', ['tab' => 'learning-groups']) }}" class="text-sm text-slate-600 dark:text-slate-400 hover:underline">&larr; Zurück zu den Lerngruppen</a>

    <x-input-error :messages="$errors->get('group')" class="mt-3" />

    <div class="mt-4 grid lg:grid-cols-5 gap-4">
        <div class="lg:col-span-2 space-y-4 self-start">
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 text-sm space-y-1">
                <div class="flex items-center justify-between">
                    <h3 class="font-medium text-slate-700 dark:text-slate-200">Überblick</h3>
                    <span class="text-xs px-2 py-0.5 rounded-full {{ $statusLabel === 'Aktiv' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300' }}">{{ $statusLabel }}</span>
                </div>
                <p class="text-slate-600 dark:text-slate-300">Plätze belegt: {{ $payload['seats']['used'] }} / {{ $payload['seats']['total'] }}</p>
                <p class="text-slate-600 dark:text-slate-300">Verwalter: {{ $group->owner?->name ?? '— (kein Verwalter)' }}</p>
                <p class="text-slate-600 dark:text-slate-300">Gültig bis: {{ $group->valid_until?->timezone('Europe/Berlin')->format('d.m.Y') ?? 'unbefristet' }}</p>
                <div class="text-slate-600 dark:text-slate-300">Kurse:
                    <ul class="list-disc ml-5">
                        @foreach ($payload['scopes'] as $scope)
                            <li>{{ $scope['course_name'] }} ({{ $scope['feature_label'] }})</li>
                        @endforeach
                    </ul>
                </div>
            </div>

            {{-- Abrechnung: jeder Beitritt verbraucht je Kurs einen freien Kurscode der Schule (außer bei eigenem Vollzugang). --}}
            @php($short = collect($payload['course_codes'])->reject(fn ($row) => $row['sufficient']))
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 text-sm space-y-2">
                <h3 class="font-medium text-slate-700 dark:text-slate-200">Kurscodes für Beitritte</h3>
                <p class="text-xs text-slate-600 dark:text-slate-400">
                    Jeder Beitritt verbraucht je Kurs einen freien Kurscode aus Ihrem Codebestand, auch bei einzelnen
                    Funktionen. Personen mit eigenem Kurszugang verbrauchen keinen Code. Entfernte Mitglieder geben ihren
                    Code nicht zurück.
                </p>
                <ul class="space-y-1">
                    @foreach ($payload['course_codes'] as $row)
                        <li class="flex justify-between gap-2" data-free-codes="{{ $row['course_code'] }}">
                            <span class="text-slate-700 dark:text-slate-200">{{ $row['course_name'] }}</span>
                            <span class="whitespace-nowrap {{ $row['sufficient'] ? 'text-slate-600 dark:text-slate-300' : 'text-amber-700 dark:text-amber-400 font-medium' }}">{{ $row['free'] }} frei</span>
                        </li>
                    @endforeach
                </ul>
                @if (! $revoked && $short->isNotEmpty())
                    <p class="text-xs rounded-lg bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300 p-2" role="alert">
                        Achtung: Für {{ $short->pluck('course_name')->join(', ', ' und ') }} gibt es weniger freie Kurscodes als
                        offene Plätze ({{ $short->first()['needed'] }}). Ohne freien Code kann nur noch beitreten, wer schon
                        einen eigenen Kurszugang hat (Personen mit eigenem Kurszugang verbrauchen keinen Code).
                    </p>
                @endif
                <a href="{{ route('admin.dashboard', ['tab' => 'coupons']) }}" class="inline-block text-xs hover:underline" style="color: var(--brand-primary, #005FD7)">Zum Codebestand (Codes importieren oder im Shop kaufen) &rarr;</a>
            </div>

            @unless ($revoked)
                <form method="POST" action="{{ route('admin.learning-groups.update', $group) }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 space-y-3">
                    @csrf
                    @method('PATCH')
                    <h3 class="font-medium text-slate-700 dark:text-slate-200">Bearbeiten</h3>
                    <div>
                        <x-input-label for="name" value="Name" />
                        <x-text-input id="name" name="name" type="text" maxlength="{{ \App\Services\LearningGroupService::MAX_NAME_LENGTH }}" class="mt-1 block w-full" :value="old('name', $group->name)" required />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="owner_user_id" value="Verwalter (aktiver Teilnehmer)" />
                        <select id="owner_user_id" name="owner_user_id" required class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                            <option value="">Bitte wählen</option>
                            @foreach ($managers as $manager)
                                <option value="{{ $manager->id }}" @selected(old('owner_user_id', $group->owner_user_id) === $manager->id)>{{ $manager->name }} ({{ $manager->email }})</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('owner_user_id')" class="mt-1" />
                    </div>
                    <div class="grid sm:grid-cols-2 gap-3">
                        <div>
                            <x-input-label for="seats" value="Plätze (mind. {{ max(1, $payload['seats']['used']) }})" />
                            <x-text-input id="seats" name="seats" type="number" min="{{ max(1, $payload['seats']['used']) }}" max="{{ \App\Services\LearningGroupService::MAX_SEATS }}" class="mt-1 block w-full" :value="old('seats', $group->seats)" required />
                            <x-input-error :messages="$errors->get('seats')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="valid_until" value="Gültig bis (leer = unbefristet)" />
                            <input id="valid_until" name="valid_until" type="date" value="{{ old('valid_until', $group->valid_until?->timezone('Europe/Berlin')->toDateString()) }}" class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                            <x-input-error :messages="$errors->get('valid_until')" class="mt-1" />
                        </div>
                    </div>
                    <p class="text-xs text-slate-600 dark:text-slate-400">Ein neues Ende gilt auch für die Kurszugänge der aktuellen Mitglieder.</p>
                    <p class="text-xs text-slate-600 dark:text-slate-400">Freie Kurscodes: {{ collect($payload['course_codes'])->map(fn ($row) => $row['course_name'].' '.$row['free'])->join(', ') }} &ndash; zusätzliche Plätze brauchen beim Beitritt je Kurs einen Code (außer für Personen mit eigenem Kurszugang).</p>
                    <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background-color: var(--brand-primary, #005FD7)">Speichern</button>
                </form>

                <form method="POST" action="{{ route('admin.learning-groups.revoke', $group) }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 space-y-2"
                      x-data="{}" @submit="if (! confirm('Diese Lerngruppe wirklich beenden? Alle Mitglieder verlieren ihren Gruppenzugang, offene Einladungen verfallen. Das lässt sich nicht rückgängig machen.')) $event.preventDefault()">
                    @csrf
                    <h3 class="font-medium text-slate-700 dark:text-slate-200">Lerngruppe beenden</h3>
                    <p class="text-xs text-slate-600 dark:text-slate-400">Beendet alle über die Gruppe erteilten Kurszugänge; eigene Zugänge der Mitglieder bleiben.</p>
                    <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium bg-red-600 hover:bg-red-700">Lerngruppe beenden</button>
                </form>
            @endunless
        </div>

        <div class="lg:col-span-3 bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden self-start">
            <div class="px-4 py-3 border-b border-slate-100 dark:border-slate-700">
                <h3 class="font-medium text-slate-700 dark:text-slate-200">Mitglieder und offene Einladungen</h3>
            </div>
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-700 text-slate-500 dark:text-slate-300 text-left">
                    <tr>
                        <th class="px-4 py-2">Name / E-Mail</th>
                        <th class="px-4 py-2">Status</th>
                        <th class="px-4 py-2"><span class="sr-only">Aktionen</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($members as $member)
                        <tr class="border-t border-slate-100 dark:border-slate-700">
                            <td class="px-4 py-2 text-slate-700 dark:text-slate-200">
                                <div>{{ $member['name'] ?? '—' }}</div>
                                <div class="text-xs text-slate-500 dark:text-slate-400">{{ $member['account_email'] ?? $member['email'] ?? '' }}</div>
                            </td>
                            <td class="px-4 py-2 text-slate-600 dark:text-slate-300 whitespace-nowrap">
                                {{ match ($member['status']) { 'active' => 'Mitglied seit '.\Illuminate\Support\Carbon::parse($member['joined_at'])->timezone('Europe/Berlin')->format('d.m.Y'), 'invited' => 'Eingeladen', 'expired' => 'Einladung abgelaufen', default => $member['status'] } }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                @if (! $revoked && $member['status'] !== 'expired')
                                    <form method="POST" action="{{ route('admin.learning-groups.members.destroy', [$group, $member['id']]) }}" x-data="{}"
                                          @submit="if (! confirm('Dieses Mitglied aus der Lerngruppe entfernen? Der Gruppenzugang endet sofort.')) $event.preventDefault()">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs text-red-600 hover:underline">Entfernen</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-3 text-slate-500 dark:text-slate-400">Noch keine Mitglieder oder Einladungen.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
    </div>
</x-app-layout>
