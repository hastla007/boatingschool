<x-superadmin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-xs text-slate-600 dark:text-slate-400"><a href="{{ route('superadmin.tenants.index') }}" class="hover:underline">Bootsschulen</a></div>
                <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">{{ $tenant->name }}</h1>
            </div>
            <span class="inline-block px-2 py-0.5 rounded-full text-xs {{ $tenant->status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500 dark:text-slate-400' }}">{{ \App\Support\StatusLabels::tenantStatus($tenant->status) }}</span>
        </div>
    </x-slot>

    <div class="grid lg:grid-cols-3 gap-4 mb-6">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 lg:col-span-1">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Stammdaten</h3>
            {{-- Sperren/Schließen trennt sofort alle Nutzer der Schule: nachfragen. --}}
            <form method="POST" action="{{ route('superadmin.tenants.update', $tenant) }}" class="space-y-3"
                  x-data="{ original: @js($tenant->status) }"
                  @submit="const s = $el.querySelector('#status').value; if (s !== original && ['suspended', 'closed'].includes(s) && ! confirm('Bootsschule wirklich ' + (s === 'closed' ? 'schließen' : 'sperren') + '? Alle Teilnehmer und das Team verlieren sofort den Zugang.')) $event.preventDefault()">
                @csrf
                @method('PATCH')
                <div>
                    <x-input-label for="name" value="Name" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $tenant->name)" required />
                </div>
                <div>
                    <x-input-label for="status" value="Status" />
                    <select id="status" name="status" class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                        {{-- Bug-Review (englische Optionen in Superadmin-Dropdowns): angezeigt wird das deutsche Label (StatusLabels), übermittelt weiterhin der rohe Wert. --}}
                        @foreach (\App\Support\StatusLabels::tenantStatusOptions() as $option => $label)
                            <option value="{{ $option }}" @selected(old('status', $tenant->status) === $option)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="custom_domain" value="Eigene Domain (optional)" />
                    <x-text-input id="custom_domain" name="custom_domain" type="text" class="mt-1 block w-full font-mono text-sm" :value="old('custom_domain', $tenant->branding->custom_domain)" placeholder="lernen.bootsschule-mueller.de" />
                    <x-input-error :messages="$errors->get('custom_domain')" class="mt-2" />
                    <details class="mt-1 text-xs text-slate-600 dark:text-slate-400">
                        <summary class="cursor-pointer">So wird die Domain eingerichtet</summary>
                        <ol class="list-decimal pl-4 mt-1 space-y-1">
                            <li>Die Bootsschule legt bei ihrem Domain-Anbieter einen CNAME-Eintrag an: <span class="font-mono">{{ old('custom_domain', $tenant->branding->custom_domain) ?: 'lernen.bootsschule-mueller.de' }}</span> → <span class="font-mono">{{ config('app.central_domain') }}</span>.</li>
                            <li>Auf dem Server wird für die Domain ein SSL-Zertifikat ausgestellt (z. B. Let's Encrypt) und sie im Webserver dieser App zugeordnet.</li>
                            <li>Domain hier eintragen und speichern. Die Adresse <span class="font-mono">{{ \App\Support\CanonicalUrl::display($tenant) }}</span> bleibt zusätzlich erreichbar; Links in E-Mails zeigen immer auf sie.</li>
                        </ol>
                    </details>
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90 transition">Speichern</button>
            </form>

            {{-- Vertragsende: Export und endgültige Löschung (TenantOffboardingService). --}}
            <div class="mt-5 pt-5 border-t border-slate-100 dark:border-slate-700 text-sm space-y-3">
                <h4 class="font-medium text-slate-700 dark:text-slate-200">Vertragsende</h4>
                <a href="{{ route('superadmin.tenants.export', $tenant) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 transition text-xs">
                    <x-icon name="download" class="w-4 h-4" /> Teilnehmer- und Code-Daten exportieren (JSON)
                </a>
                @if ($tenant->status === 'closed')
                    <form method="POST" action="{{ route('superadmin.tenants.destroy', $tenant) }}" class="space-y-2 p-3 rounded-lg bg-red-50 dark:bg-red-950/40"
                          x-data="{}" @submit="if (! confirm('Bootsschule endgültig löschen? Das lässt sich nicht rückgängig machen.')) $event.preventDefault()">
                        @csrf
                        @method('DELETE')
                        <p class="text-xs text-red-900 dark:text-red-100">
                            Löscht die Bootsschule mit allen Mitgliedschaften, Freischaltungen, Lernständen, Anmeldungen, Bestellungen,
                            dem Protokoll sowie Logo und Favicon. Konten, die keiner anderen Bootsschule angehören, werden ebenfalls gelöscht.
                            Vorher die Daten exportieren.
                        </p>
                        <x-input-label for="confirm_slug" value="Zur Bestätigung den Kurznamen eintippen: {{ $tenant->slug }}" />
                        <x-text-input id="confirm_slug" name="confirm_slug" type="text" class="block w-full font-mono text-sm" autocomplete="off" />
                        <x-input-error :messages="$errors->get('confirm_slug')" class="mt-1" />
                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-red-700 text-white text-xs font-medium hover:opacity-90 transition">Endgültig löschen</button>
                    </form>
                @else
                    <p class="text-xs text-slate-500 dark:text-slate-400">Löschen ist erst möglich, wenn der Status auf „geschlossen“ steht.</p>
                @endif
            </div>

            <div class="mt-5 pt-5 border-t border-slate-100 dark:border-slate-700 text-sm space-y-1.5">
                <div class="flex justify-between"><span class="text-slate-600 dark:text-slate-400">Domain</span><a href="{{ \App\Support\CanonicalUrl::root($tenant) }}" target="_blank" rel="noopener" class="font-mono text-xs text-slate-600 dark:text-slate-300 hover:underline">{{ \App\Support\CanonicalUrl::display($tenant) }}</a></div>
                @if ($tenant->branding->custom_domain)
                    <div class="flex justify-between"><span class="text-slate-600 dark:text-slate-400">Eigene Domain</span><span class="font-mono text-xs text-slate-600 dark:text-slate-300">{{ $tenant->branding->custom_domain }}</span></div>
                @endif
                <div class="flex justify-between"><span class="text-slate-600 dark:text-slate-400">Support-E-Mail</span><span class="text-slate-600 dark:text-slate-300">{{ $tenant->branding->support_email ?: '—' }}</span></div>
                <div class="flex justify-between"><span class="text-slate-600 dark:text-slate-400">Website</span><span class="text-slate-600 dark:text-slate-300">{{ $tenant->branding->website ?: '—' }}</span></div>
                <div class="flex justify-between"><span class="text-slate-600 dark:text-slate-400">Telefon</span><span class="text-slate-600 dark:text-slate-300">{{ $tenant->branding->phone ?: '—' }}</span></div>
                <div class="flex justify-between"><span class="text-slate-600 dark:text-slate-400">Ansprechpartner</span><span class="text-slate-600 dark:text-slate-300">{{ trim(($tenant->branding->contact_first_name ?? '').' '.($tenant->branding->contact_last_name ?? '')) ?: '—' }}</span></div>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 lg:col-span-2">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
                <x-icon name="users" class="w-4 h-4 text-slate-600 dark:text-slate-400" /> Nutzer &amp; Rollen
            </h3>
            <div class="space-y-2 text-sm max-h-80 overflow-y-auto">
                @forelse ($memberships as $membership)
                    <a href="{{ route('superadmin.users.show', $membership->user) }}" class="flex items-center justify-between border-b border-slate-100 dark:border-slate-700 pb-2 last:border-0 hover:bg-slate-50 dark:hover:bg-slate-700/50 -mx-1 px-1 rounded">
                        <span class="text-slate-700 dark:text-slate-200">{{ $membership->user->name }} <span class="text-slate-600 dark:text-slate-400 font-normal">&middot; {{ $membership->user->email }}</span></span>
                        <span class="inline-block px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-xs">{{ \App\Support\StatusLabels::tenantRole($membership->role) }}</span>
                    </a>
                @empty
                    <p class="text-slate-500 dark:text-slate-400">Noch keine Nutzer.</p>
                @endforelse
            </div>
            <div class="mt-2">{{ $memberships->links() }}</div>
        </div>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 mb-6">
        <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
            <x-icon name="chart-bar" class="w-4 h-4 text-slate-600 dark:text-slate-400" /> Kursergebnisse
        </h3>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-slate-600 dark:text-slate-400 text-left">
                <tr><th class="py-1">Nutzer</th><th class="py-1">Kurs</th><th class="py-1">Fortschritt</th></tr>
            </thead>
            <tbody>
                @forelse ($results as $result)
                    <tr class="border-t border-slate-100 dark:border-slate-700">
                        <td class="py-1.5">{{ $result['entitlement']->user->name }}</td>
                        <td class="py-1.5 text-slate-500 dark:text-slate-400">{{ $result['entitlement']->course->name }}</td>
                        <td class="py-1.5 text-slate-500 dark:text-slate-400">{{ $result['percent'] }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="py-3 text-slate-500 dark:text-slate-400">Noch keine Kurszugänge.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
        <div class="mt-2">{{ $results->links() }}</div>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 flex items-center gap-1.5">
                <x-icon name="bookmark" class="w-4 h-4 text-slate-600 dark:text-slate-400" /> Zugangscodes dieser Bootsschule
            </h3>
            <a href="{{ route('superadmin.coupons.index', ['tenant_id' => $tenant->id]) }}" class="text-sm hover:underline text-blue-700 dark:text-blue-300">Bei den Zugangscodes verwalten &rarr;</a>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-slate-600 dark:text-slate-400 text-left">
                <tr><th class="py-1">Code</th><th class="py-1">Kurs / Produkt</th><th class="py-1">Status</th></tr>
            </thead>
            <tbody>
                @forelse ($coupons->take(10) as $coupon)
                    <tr class="border-t border-slate-100 dark:border-slate-700">
                        <td class="py-1.5 font-mono text-xs">{{ $coupon->code }}</td>
                        <td class="py-1.5 text-slate-500 dark:text-slate-400">{{ $coupon->redeemableName() }}</td>
                        <td class="py-1.5">
                            @if ($coupon->isRedeemed())
                                <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400">
                                    eingelöst von {{ $coupon->redeemedBy?->name }}
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700">offen</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="py-3 text-slate-500 dark:text-slate-400">Noch keine Codes für diese Bootsschule.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</x-superadmin-layout>
