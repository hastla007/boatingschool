<x-superadmin-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Lead <span class="font-mono text-base">{{ $lead->public_id }}</span></h1>
    </x-slot>

    <p class="mb-4 text-sm"><a href="{{ route('superadmin.leads.index') }}" class="text-sky-700 dark:text-sky-300 hover:underline">← Alle Leads</a></p>

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-red-50 dark:bg-red-900/30 p-3 text-sm text-red-800 dark:text-red-200" role="alert">
            @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    <div class="grid lg:grid-cols-3 gap-4">
        <div class="lg:col-span-2 bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 text-sm">
            <dl class="grid sm:grid-cols-3 gap-x-4 gap-y-2">
                <dt class="text-slate-500 dark:text-slate-400">Status</dt><dd class="sm:col-span-2">{{ $lead->statusLabel() }}</dd>
                <dt class="text-slate-500 dark:text-slate-400">Name</dt><dd class="sm:col-span-2">{{ $lead->contact_name ?: '—' }}</dd>
                <dt class="text-slate-500 dark:text-slate-400">E-Mail</dt><dd class="sm:col-span-2">{{ $lead->contact_email }}</dd>
                <dt class="text-slate-500 dark:text-slate-400">Telefon</dt><dd class="sm:col-span-2">{{ $lead->contact_phone ?: '—' }}</dd>
                <dt class="text-slate-500 dark:text-slate-400">Organisation</dt><dd class="sm:col-span-2">{{ $lead->contact_organisation ?: '—' }}</dd>
                <dt class="text-slate-500 dark:text-slate-400">Rolle</dt><dd class="sm:col-span-2">{{ $lead->contact_role ?: '—' }}</dd>
                <dt class="text-slate-500 dark:text-slate-400">Anliegen</dt><dd class="sm:col-span-2">{{ \App\Models\FormCaptainLead::PURPOSE_LABELS[$lead->purpose] ?? '—' }}</dd>
                <dt class="text-slate-500 dark:text-slate-400">Schulgröße</dt><dd class="sm:col-span-2">{{ $lead->school_size ?: '—' }}</dd>
                <dt class="text-slate-500 dark:text-slate-400">Nachricht</dt><dd class="sm:col-span-2 whitespace-pre-line">{{ $lead->message ?: '—' }}</dd>
                <dt class="text-slate-500 dark:text-slate-400">Einwilligung</dt>
                <dd class="sm:col-span-2">am {{ \App\Support\GermanTime::format($lead->consent_given_at, 'd.m.Y H:i') }}, Textfassung {{ $lead->consent_text_revision }}
                    @if ($lead->consent_text_hash)<span class="block text-xs font-mono text-slate-500 dark:text-slate-400 break-all">{{ $lead->consent_text_hash }}</span>@endif
                    <span class="block text-xs text-slate-500 dark:text-slate-400">Nachweis und Einwilligungstext liegen im Form-Captain-Eintrag der Website.</span></dd>
                <dt class="text-slate-500 dark:text-slate-400">Herkunft</dt>
                <dd class="sm:col-span-2 break-all">{{ $lead->site_url }} · Formular {{ $lead->form_id }}, Eintrag {{ $lead->entry_id }}
                    @if ($lead->page_url)<span class="block text-xs text-slate-500 dark:text-slate-400">Seite: {{ $lead->page_url }}</span>@endif
                    <span class="block text-xs text-slate-500 dark:text-slate-400">Schlüssel {{ $lead->key_id }}@if ($lead->plugin_version) · Connector {{ $lead->plugin_version }}@endif</span></dd>
                <dt class="text-slate-500 dark:text-slate-400">Eingang</dt>
                <dd class="sm:col-span-2">{{ \App\Support\GermanTime::format($lead->created_at, 'd.m.Y H:i') }} (abgeschickt {{ \App\Support\GermanTime::format($lead->submitted_at, 'd.m.Y H:i') }})
                    @if ($lead->merged_count > 0)<span class="block text-xs text-amber-700 dark:text-amber-300">{{ $lead->merged_count }} weitere Einsendung(en) dieser Adresse über der Tagesgrenze, zuletzt {{ \App\Support\GermanTime::format($lead->last_merged_at, 'd.m.Y H:i') }} (nicht gespeichert).</span>@endif</dd>
                <dt class="text-slate-500 dark:text-slate-400">Aufbewahrung bis</dt><dd class="sm:col-span-2">{{ \App\Support\GermanTime::format($lead->retain_until, 'd.m.Y') }}</dd>
                @if ($lead->handledBy)
                    <dt class="text-slate-500 dark:text-slate-400">Zuletzt bearbeitet</dt><dd class="sm:col-span-2">{{ $lead->handledBy->name }}@if ($lead->status_changed_at), Status seit {{ \App\Support\GermanTime::format($lead->status_changed_at, 'd.m.Y H:i') }}@endif</dd>
                @endif
                @if ($lead->status === \App\Models\FormCaptainLead::REJECTED && $lead->rejection_reason)
                    <dt class="text-slate-500 dark:text-slate-400">Ablehnungsgrund</dt><dd class="sm:col-span-2">{{ $lead->rejection_reason }}</dd>
                @endif
            </dl>
        </div>

        <div class="space-y-4">
            <form method="POST" action="{{ route('superadmin.leads.update', $lead) }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 space-y-3 text-sm">
                @csrf
                @method('PATCH')
                <div>
                    <label for="lead-status" class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Status</label>
                    <select id="lead-status" name="status" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                        @foreach (\App\Models\FormCaptainLead::STATUS_LABELS as $value => $label)
                            <option value="{{ $value }}" @selected(old('status', $lead->status) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="lead-notes" class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Interne Notiz</label>
                    <textarea id="lead-notes" name="notes" rows="5" maxlength="5000" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">{{ old('notes', $lead->notes) }}</textarea>
                </div>
                <button type="submit" class="px-3 py-2 rounded-lg bg-slate-900 text-white">Speichern</button>
            </form>

            @if ($lead->status !== \App\Models\FormCaptainLead::REJECTED)
                <form method="POST" action="{{ route('superadmin.leads.reject', $lead) }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 space-y-2 text-sm">
                    @csrf
                    <label for="lead-reason" class="block text-xs text-slate-600 dark:text-slate-300">Ablehnen (Grund, intern)</label>
                    <input id="lead-reason" name="rejection_reason" maxlength="500" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                    <button type="submit" class="px-3 py-2 rounded-lg border border-red-300 text-red-700 dark:text-red-300">Ablehnen</button>
                </form>
            @endif

            <form method="POST" action="{{ route('superadmin.leads.destroy', $lead) }}" x-data="{}"
                  @submit="if (! confirm('Lead endgültig löschen? Der Eintrag in Form Captain bleibt unverändert.')) $event.preventDefault()"
                  class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 text-sm">
                @csrf
                @method('DELETE')
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-2">Löscht den Lead sofort und endgültig. Eine spätere Wiederholung derselben Zustellung legt ihn nicht neu an.</p>
                <button type="submit" class="px-3 py-2 rounded-lg border border-red-300 text-red-700 dark:text-red-300">Lead löschen</button>
            </form>
        </div>
    </div>
</x-superadmin-layout>
