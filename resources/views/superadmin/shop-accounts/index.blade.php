<x-superadmin-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Shopkonten</h1>
    </x-slot>

    <p class="text-sm text-slate-600 dark:text-slate-300 mb-4 max-w-3xl">
        Kundenkonten des B2B-Shops. Neue Registrierungen werden hier geprüft und einer Bootsschule zugeordnet
        (bestehende Schule: Rolle Admin, neue Schule: Rolle Inhaber). Erst danach kann über das Shopkonto für die
        Schule eingekauft werden; gekaufte Codes landen im Codebestand der Schule.
        @unless ($shopConfigured)
            <span class="block mt-2 text-amber-700 dark:text-amber-300">Die Shop-Anbindung ist noch nicht konfiguriert (COMMERCE_SHOP_SECRET, COMMERCE_SHOP_URL, siehe docs/commerce/README.md).</span>
        @endunless
    </p>

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-red-50 dark:bg-red-900/30 p-3 text-sm text-red-800 dark:text-red-200" role="alert">
            @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    <form method="GET" class="mb-4 flex gap-2 items-end">
        <div>
            <label for="status" class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Anzeigen</label>
            <select id="status" name="status" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                @foreach (['open' => 'Offen (Prüfung, Fehler)', 'provisioning' => 'Anlage im Shop läuft', 'linked' => 'Verknüpft', 'rejected' => 'Abgelehnt', 'revoked' => 'Gelöst/deaktiviert', 'all' => 'Alle'] as $value => $label)
                    <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="px-3 py-2 rounded-lg bg-slate-900 text-white text-sm">Filtern</button>
    </form>

    <div class="space-y-4">
        @forelse ($accounts as $account)
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
                <div class="flex flex-wrap justify-between gap-3">
                    <div>
                        <p class="font-medium text-slate-800 dark:text-slate-100">{{ $account->company_name ?: '—' }} · {{ $account->email }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            {{ $account->contact_name ?: 'ohne Kontaktnamen' }}
                            @if ($account->billing_country) · {{ $account->billing_country }} @endif
                            @if ($account->vat_id) · USt-ID {{ $account->vat_id }} @endif
                            · Shopkunde {{ $account->shop_customer_id ?? 'noch nicht angelegt' }}
                            · seit {{ \App\Support\GermanTime::format($account->created_at, 'd.m.Y H:i') }}
                        </p>
                    </div>
                    <span class="self-start inline-block px-2 py-0.5 rounded-full text-xs bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200">
                        {{ ['pending_review' => 'In Prüfung', 'awaiting_link' => 'Wartet auf Verknüpfung', 'linked' => 'Verknüpft', 'rejected' => 'Abgelehnt', 'revoked' => 'Gelöst'][$account->status] ?? $account->status }}
                        @if ($account->tenant) · {{ $account->tenant->name }} @endif
                    </span>
                </div>

                @if ($account->status === 'awaiting_link')
                    <p class="mt-2 text-xs text-amber-700 dark:text-amber-300">
                        Diese Adresse hat bereits ein App-Konto. Weil die im Shop angegebene Adresse nicht bestätigt ist,
                        verknüpft nur ein Verknüpfungscode aus diesem App-Konto (Admin-Bereich → Codes → „Shop verknüpfen“).
                        Hier können Sie das Shopkonto nur ablehnen. Ist die Person noch nicht Inhaberin oder Admin einer Schule
                        (z. B. bisher Lernende), legen Sie die Schule unter „Schulen“ mit dieser E-Mail als Inhaber an; danach
                        verknüpft sie per Code.
                    </p>
                @endif

                @if ($account->provisioning_status === 'failed')
                    <div class="mt-3 rounded-lg bg-red-50 dark:bg-red-900/30 p-3 text-sm text-red-800 dark:text-red-200">
                        Shopkonto ausstehend/Fehler: {{ $account->provisioning_error }}
                        <form method="POST" action="{{ route('superadmin.shop-accounts.retry', $account) }}" class="inline">
                            @csrf
                            <button type="submit" class="ml-2 underline">Erneut versuchen</button>
                        </form>
                    </div>
                @elseif ($account->provisioning_status === 'needs_link')
                    <p class="mt-2 text-xs text-amber-700 dark:text-amber-300">
                        Im Shop gibt es bereits ein Kundenkonto mit dieser Adresse. Es wurde nicht automatisch übernommen:
                        Die Schule erzeugt in der App einen Verknüpfungscode (Admin-Bereich → Codes) und gibt ihn im Shop unter
                        „Mein Konto → Bootsschule verknüpfen“ ein.
                        @if ($account->provisioning_error) {{ $account->provisioning_error }} @endif
                    </p>
                @elseif ($account->provisioning_status === 'pending')
                    @php($outEvent = $outEvents[$account->id] ?? null)
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                        @if ($outEvent?->status === 'sending')
                            Shopkonto wird angelegt – wird gerade an den Shop gesendet (seit {{ \App\Support\GermanTime::format($outEvent->claimed_at, 'H:i') }} Uhr).
                        @elseif ($outEvent?->status === 'pending' && $outEvent->attempts > 0)
                            Shopkonto wird angelegt – {{ $outEvent->attempts }}. Versuch fehlgeschlagen, nächster Versuch um {{ \App\Support\GermanTime::format($outEvent->available_at, 'H:i') }} Uhr.
                            @if ($outEvent->last_error)<span class="block">{{ $outEvent->last_error }}</span>@endif
                        @else
                            Shopkonto wird angelegt …
                        @endif
                    </p>
                @endif

                @if (in_array($account->status, ['pending_review', 'awaiting_link'], true))
                    <div class="mt-4 grid md:grid-cols-3 gap-4 text-sm">
                        @if ($account->status === 'pending_review')
                        <form method="POST" action="{{ route('superadmin.shop-accounts.approve', $account) }}" class="space-y-2">
                            @csrf
                            <input type="hidden" name="mode" value="existing">
                            <label for="tenant-{{ $account->id }}" class="block text-xs text-slate-600 dark:text-slate-300">Bestehender Bootsschule zuordnen</label>
                            <select id="tenant-{{ $account->id }}" name="tenant_id" required class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                                <option value="">Bitte wählen</option>
                                @foreach ($tenants as $tenant)
                                    <option value="{{ $tenant->id }}">{{ $tenant->name }} ({{ $tenant->slug }})</option>
                                @endforeach
                            </select>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Die Inhaber der Schule werden per E-Mail informiert.</p>
                            <button type="submit" class="px-3 py-2 rounded-lg bg-slate-900 text-white">Zuordnen &amp; freigeben</button>
                        </form>
                        <form method="POST" action="{{ route('superadmin.shop-accounts.approve', $account) }}" class="space-y-2">
                            @csrf
                            <input type="hidden" name="mode" value="new">
                            <label for="name-{{ $account->id }}" class="block text-xs text-slate-600 dark:text-slate-300">Als neue Bootsschule freigeben</label>
                            <input id="name-{{ $account->id }}" name="name" required value="{{ $account->company_name }}" placeholder="Name der Bootsschule" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                            <label for="slug-{{ $account->id }}" class="sr-only">Slug</label>
                            <input id="slug-{{ $account->id }}" name="slug" required value="{{ \Illuminate\Support\Str::slug($account->company_name ?? '') }}" placeholder="slug (z. B. meine-bootsschule)" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm font-mono">
                            <button type="submit" class="px-3 py-2 rounded-lg bg-slate-900 text-white">Schule anlegen &amp; freigeben</button>
                        </form>
                        @endif
                        <form method="POST" action="{{ route('superadmin.shop-accounts.reject', $account) }}" class="space-y-2">
                            @csrf
                            <label for="note-{{ $account->id }}" class="block text-xs text-slate-600 dark:text-slate-300">Ablehnen (Grund, intern)</label>
                            <input id="note-{{ $account->id }}" name="note" maxlength="500" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                            <button type="submit" class="px-3 py-2 rounded-lg border border-red-300 text-red-700 dark:text-red-300">Ablehnen</button>
                        </form>
                    </div>
                @endif
            </div>
        @empty
            <p class="text-sm text-slate-500 dark:text-slate-400">Keine Shopkonten in dieser Ansicht.</p>
        @endforelse
    </div>

    <div class="mt-4">{{ $accounts->links() }}</div>
</x-superadmin-layout>
