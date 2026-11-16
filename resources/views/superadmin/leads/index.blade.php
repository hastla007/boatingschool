<x-superadmin-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Leads</h1>
    </x-slot>

    <p class="text-sm text-slate-600 dark:text-slate-300 mb-4 max-w-3xl">
        Interessenten aus den Form-Captain-Formularen der Website (Demo, Beratung, Infos zum Schulzugang).
        Ein Lead ist keinem Konto und keiner Schule zugeordnet; aus ihm entsteht hier nichts automatisch.
        Soll daraus eine Bootsschule werden, legen Sie sie wie bisher unter „Bootsschulen“ an.
        @if ($keys === [])
            <span class="block mt-2 text-amber-700 dark:text-amber-300">Der Lead-Eingang ist noch nicht konfiguriert (FORM_CAPTAIN_LEAD_SECRET, siehe docs/integrations/form-captain-leads.md).</span>
        @endif
    </p>

    @if ($keys !== [])
        <div class="mb-4 text-xs text-slate-500 dark:text-slate-400">
            Schlüssel:
            @foreach ($keys as $key)
                <span class="inline-block mr-3"><span class="font-mono">{{ $key['id'] }}</span>
                    @if ($key['revoked']) (widerrufen) @endif
                    – zuletzt benutzt: {{ $key['last_used'] ? \App\Support\GermanTime::format(\Illuminate\Support\Carbon::parse($key['last_used']), 'd.m.Y H:i') : 'nie' }}</span>
            @endforeach
        </div>
    @endif

    <form method="GET" class="mb-4 flex flex-wrap gap-2 items-end">
        <div>
            <label for="status" class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Anzeigen</label>
            <select id="status" name="status" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                @foreach (['open' => 'Offen (neu, in Bearbeitung)'] + \App\Models\FormCaptainLead::STATUS_LABELS + ['all' => 'Alle'] as $value => $label)
                    <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="purpose" class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Zweck</label>
            <select id="purpose" name="purpose" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                @foreach (['' => 'Alle Zwecke'] + \App\Models\FormCaptainLead::PURPOSE_LABELS as $value => $label)
                    <option value="{{ $value }}" @selected($purpose === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="q" class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Suche (E-Mail, Name, Organisation, Lead-ID)</label>
            <input id="q" name="q" value="{{ $search }}" maxlength="190" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
        </div>
        <button type="submit" class="px-3 py-2 rounded-lg bg-slate-900 text-white text-sm">Filtern</button>
    </form>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="text-left text-xs text-slate-500 dark:text-slate-400">
                <tr>
                    <th scope="col" class="px-4 py-2">Eingang</th>
                    <th scope="col" class="px-4 py-2">Kontakt</th>
                    <th scope="col" class="px-4 py-2">Anliegen</th>
                    <th scope="col" class="px-4 py-2">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($leads as $lead)
                    <tr class="border-t border-slate-100 dark:border-slate-700">
                        <td class="px-4 py-2 whitespace-nowrap">
                            <a href="{{ route('superadmin.leads.show', $lead) }}" class="text-sky-700 dark:text-sky-300 hover:underline">{{ \App\Support\GermanTime::format($lead->created_at, 'd.m.Y H:i') }}</a>
                            <span class="block text-xs font-mono text-slate-500 dark:text-slate-400">{{ $lead->public_id }}</span>
                        </td>
                        <td class="px-4 py-2">
                            {{ $lead->contact_name ?: '—' }} · {{ $lead->contact_email }}
                            @if ($lead->contact_organisation)<span class="block text-xs text-slate-500 dark:text-slate-400">{{ $lead->contact_organisation }}</span>@endif
                        </td>
                        <td class="px-4 py-2">
                            {{ \App\Models\FormCaptainLead::PURPOSE_LABELS[$lead->purpose] ?? '—' }}
                            @if ($lead->product_name)<span class="block text-xs text-slate-500 dark:text-slate-400">{{ $lead->product_name }}</span>@endif
                            @if ($lead->merged_count > 0)<span class="block text-xs text-amber-700 dark:text-amber-300">+{{ $lead->merged_count }} weitere Einsendung(en)</span>@endif
                        </td>
                        <td class="px-4 py-2">
                            <span class="inline-block px-2 py-0.5 rounded-full text-xs bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200">{{ $lead->statusLabel() }}</span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-4 text-slate-500 dark:text-slate-400">Keine Leads in dieser Ansicht.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $leads->links() }}</div>
</x-superadmin-layout>
