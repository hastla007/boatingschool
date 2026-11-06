<x-superadmin-layout>
    <x-slot name="header">
        <div>
            <div class="text-xs text-slate-600 dark:text-slate-400"><a href="{{ route('superadmin.users.index') }}" class="hover:underline">Nutzer</a></div>
            <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">{{ $user->name }}</h1>
            <div class="text-xs mt-1 flex gap-3">
                <a href="{{ route('superadmin.audit.index', ['actor' => $user->email]) }}" class="text-slate-600 dark:text-slate-400 hover:underline">Protokoll: Aktionen dieser Person</a>
                <a href="{{ route('superadmin.audit.index', ['entity' => $user->id]) }}" class="text-slate-600 dark:text-slate-400 hover:underline">Protokoll: Änderungen am Konto</a>
            </div>
        </div>
    </x-slot>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 lg:col-span-1">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Stammdaten</h3>
            <form method="POST" action="{{ route('superadmin.users.update', $user) }}" class="space-y-3">
                @csrf
                @method('PATCH')

                @php
                    // Bug-Review (Superadmin-Nutzerseite zeigt leere
                    // Namensfelder): first_name/last_name blieben bei
                    // Nutzern, die sich selbst registriert oder eine
                    // Einladung angenommen haben, bisher unbesetzt (siehe
                    // User::splitDisplayName()) -- diese, als required
                    // geführten, Felder zeigten dadurch leer an, obwohl
                    // display_name längst einen Namen enthält. Nur zur
                    // Vorbefüllung wird hier -- sofern noch nicht gesetzt --
                    // derselbe Vorschlag wie bei Neuanlagen abgeleitet; die
                    // Spalten selbst ändern sich dadurch nicht.
                    $guessedName = ($user->first_name === null && $user->last_name === null)
                        ? \App\Models\User::splitDisplayName($user->name)
                        : ['first_name' => $user->first_name, 'last_name' => $user->last_name];
                @endphp

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <x-input-label for="first_name" value="Vorname" />
                        <x-text-input id="first_name" name="first_name" type="text" class="mt-1 block w-full" :value="old('first_name', $guessedName['first_name'])" required />
                    </div>
                    <div>
                        <x-input-label for="last_name" value="Nachname" />
                        <x-text-input id="last_name" name="last_name" type="text" class="mt-1 block w-full" :value="old('last_name', $guessedName['last_name'])" />
                    </div>
                </div>
                <div>
                    <x-input-label for="email" value="E-Mail" />
                    <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" required />
                    <x-input-error :messages="$errors->get('email')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="phone" value="Telefonnummer" />
                    <x-text-input id="phone" name="phone" type="text" class="mt-1 block w-full" :value="old('phone', $user->phone)" />
                </div>
                {{--
                    Sicherheitsreview S3: Adresse/Geburtsdatum liegen seit
                    Migration 000469 je Mitgliedschaft auf tenant_user, nicht
                    mehr kontoweit auf app_user -- diese plattformweite Seite
                    kennt keine einzelne Bootsschule, der sie eine Adresse
                    zuordnen könnte. Das bearbeitet jede Bootsschule jetzt
                    selbst (Profil/Teilnehmerverwaltung).
                --}}
                <div>
                    <x-input-label for="status" value="Status" />
                    <select id="status" name="status" class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                        {{-- Bug-Review (englische Optionen in Superadmin-Dropdowns): angezeigt wird das deutsche Label (StatusLabels), übermittelt weiterhin der rohe Wert. --}}
                        @foreach (\App\Support\StatusLabels::userStatusOptions() as $option => $label)
                            <option value="{{ $option }}" @selected(old('status', $user->status) === $option)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300 pt-1">
                    <input type="checkbox" name="is_superadmin" value="1" @checked(old('is_superadmin', $user->is_superadmin)) class="rounded border-slate-300">
                    Superadmin (plattformweiter Zugriff)
                </label>

                <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90 transition">Speichern</button>
            </form>

            <div class="mt-5 pt-5 border-t border-slate-100 dark:border-slate-700 space-y-2 text-sm">
                <h4 class="font-medium text-slate-700 dark:text-slate-200">Zwei-Faktor-Anmeldung</h4>
                @if ($user->hasTwoFactor() || $user->two_factor_secret)
                    <p class="text-slate-600 dark:text-slate-400">{{ $user->hasTwoFactor() ? 'Aktiv' : 'Eingerichtet, aber nicht bestätigt' }}. Zurücksetzen nur nach sicherer Identitätsprüfung (z. B. Rückruf).</p>
                    <form method="POST" action="{{ route('superadmin.users.two-factor.reset', $user) }}" x-data="{}"
                          @submit="if (! confirm('Zwei-Faktor-Anmeldung dieses Kontos wirklich zurücksetzen?')) $event.preventDefault()">
                        @csrf
                        <button type="submit" class="px-3 py-1.5 rounded-lg border border-amber-300 text-amber-800 dark:border-amber-700 dark:text-amber-200 text-xs font-medium hover:bg-amber-50 dark:hover:bg-amber-900/30">2FA zurücksetzen</button>
                    </form>
                @else
                    <p class="text-slate-600 dark:text-slate-400">Nicht eingerichtet.</p>
                @endif
            </div>

            @unless ($user->id === auth()->id())
                <details class="mt-5 pt-5 border-t border-slate-100 dark:border-slate-700 text-sm" @if ($errors->has('confirm_email')) open @endif>
                    <summary class="cursor-pointer font-medium text-rose-700 dark:text-rose-300">Konto löschen</summary>
                    <p class="mt-2 text-slate-600 dark:text-slate-400">Löscht das Konto bei allen Bootsschulen samt Lernständen, Freischaltungen, Anmeldungen und Bestellungen. Das Protokoll wird pseudonymisiert. Nicht rückgängig zu machen.</p>
                    <form method="POST" action="{{ route('superadmin.users.destroy', $user) }}" class="mt-2 space-y-2">
                        @csrf
                        @method('DELETE')
                        <x-input-label for="confirm_email" value="Zur Bestätigung die E-Mail-Adresse eintippen" />
                        <x-text-input id="confirm_email" name="confirm_email" type="text" class="block w-full" autocomplete="off" />
                        <x-input-error :messages="$errors->get('confirm_email')" />
                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-rose-600 text-white text-xs font-medium hover:opacity-90">Konto endgültig löschen</button>
                    </form>
                </details>
            @endunless
        </div>

        <div class="lg:col-span-2 space-y-4">
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
                <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
                    <x-icon name="swatch" class="w-4 h-4 text-slate-600 dark:text-slate-400" /> Bootsschul-Zuordnungen
                </h3>
                @error('impersonate')
                    <p class="mb-3 text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
                @enderror
                @error('membership')
                    <p class="mb-3 text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
                @enderror
                <div class="space-y-2 text-sm mb-4">
                    @forelse ($memberships as $membership)
                        <div class="border-b border-slate-100 dark:border-slate-700 pb-2 last:border-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('superadmin.tenants.show', $membership->tenant) }}" class="flex-1 min-w-32 text-slate-700 dark:text-slate-200 hover:underline">{{ $membership->tenant->name }}</a>
                                <form method="POST" action="{{ route('superadmin.users.memberships.update', [$user, $membership->tenant]) }}" class="flex flex-wrap items-center gap-2">
                                    @csrf
                                    @method('PATCH')
                                    <label for="role-{{ $membership->tenant_id }}" class="sr-only">Rolle bei {{ $membership->tenant->name }}</label>
                                    <select id="role-{{ $membership->tenant_id }}" name="role" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-xs">
                                        @foreach (\App\Support\StatusLabels::tenantRoleOptions() as $role => $label)
                                            <option value="{{ $role }}" @selected($membership->role === $role)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <label for="mstatus-{{ $membership->tenant_id }}" class="sr-only">Status bei {{ $membership->tenant->name }}</label>
                                    <select id="mstatus-{{ $membership->tenant_id }}" name="status" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-xs">
                                        @foreach (['active', 'suspended', 'closed'] as $mstatus)
                                            <option value="{{ $mstatus }}" @selected($membership->status === $mstatus)>{{ \App\Support\StatusLabels::userStatus($mstatus) }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="px-2 py-1 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 text-xs">Speichern</button>
                                </form>
                                @if ($membership->status === 'active' && $membership->tenant->status === 'active' && ! $user->is_superadmin && $user->status === 'active' && $user->id !== auth()->id())
                                    <form method="POST" action="{{ route('superadmin.users.impersonate', [$user, $membership->tenant]) }}" x-data="{}"
                                          @submit="if (! confirm('Als {{ addslashes($user->name) }} bei {{ addslashes($membership->tenant->name) }} ansehen? Die Ansicht ist nur lesend und wird protokolliert.')) $event.preventDefault()">
                                        @csrf
                                        <button type="submit" class="text-xs text-sky-700 dark:text-sky-300 hover:underline">Als Nutzer ansehen</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('superadmin.users.memberships.destroy', [$user, $membership->tenant]) }}" x-data="{}"
                                      @submit="if (! confirm('{{ $memberships->count() === 1 ? 'Letzte Mitgliedschaft: Das Konto wird dabei komplett gelöscht. Fortfahren?' : 'Mitgliedschaft samt Lerndaten bei dieser Schule löschen?' }}')) $event.preventDefault()">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs text-rose-600 hover:underline">Entfernen</button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <p class="text-slate-500 dark:text-slate-400">Noch keine Bootsschule zugeordnet.</p>
                    @endforelse
                </div>
                <form method="POST" action="{{ route('superadmin.users.memberships.store', $user) }}" class="flex flex-wrap gap-2">
                    @csrf
                    <label for="membership_tenant_id" class="sr-only">Bootsschule</label>
                    <select id="membership_tenant_id" name="tenant_id" required class="flex-1 min-w-0 basis-40 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-xs">
                        @foreach ($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                    <label for="membership_role" class="sr-only">Rolle</label>
                    <select id="membership_role" name="role" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-xs">
                        @foreach (\App\Support\StatusLabels::tenantRoleOptions() as $role => $label)
                            <option value="{{ $role }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="px-3 py-1 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 transition text-xs shrink-0">Zuordnen</button>
                </form>
            </div>

            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
                <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
                    <x-icon name="chart-bar" class="w-4 h-4 text-slate-600 dark:text-slate-400" /> Kursergebnisse
                </h3>
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-slate-600 dark:text-slate-400 text-left"><tr><th class="py-1">Kurs</th><th class="py-1">Bootsschule</th><th class="py-1">Fortschritt</th></tr></thead>
                    <tbody>
                        @forelse ($results as $result)
                            <tr class="border-t border-slate-100 dark:border-slate-700">
                                <td class="py-1.5">{{ $result['entitlement']->course->name }}</td>
                                <td class="py-1.5 text-slate-500 dark:text-slate-400">{{ $result['entitlement']->tenant->name }}</td>
                                <td class="py-1.5 text-slate-500 dark:text-slate-400">{{ $result['percent'] }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="py-3 text-slate-500 dark:text-slate-400">Noch keine Kurszugänge.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
                <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
                    <x-icon name="cube" class="w-4 h-4 text-slate-600 dark:text-slate-400" /> Gekaufte Produkte
                </h3>
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-slate-600 dark:text-slate-400 text-left"><tr><th class="py-1">Produkt</th><th class="py-1">Bootsschule</th><th class="py-1">Quelle</th><th class="py-1">Gekauft am</th></tr></thead>
                    <tbody>
                        @forelse ($productPurchases as $purchase)
                            <tr class="border-t border-slate-100 dark:border-slate-700">
                                <td class="py-1.5">{{ $purchase->product->name }}</td>
                                <td class="py-1.5 text-slate-500 dark:text-slate-400">{{ $purchase->tenant->name }}</td>
                                <td class="py-1.5 text-slate-500 dark:text-slate-400">{{ $purchase->source_type === 'coupon' ? 'Coupon '.$purchase->source_reference : $purchase->source_type }}</td>
                                <td class="py-1.5 text-slate-500 dark:text-slate-400">{{ \App\Support\GermanTime::format($purchase->created_at, 'd.m.Y H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-3 text-slate-500 dark:text-slate-400">Noch keine Produkte gekauft.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
                <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
                    <x-icon name="clipboard-document-check" class="w-4 h-4 text-slate-600 dark:text-slate-400" /> Prüfungssimulationen
                </h3>
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-slate-600 dark:text-slate-400 text-left"><tr><th class="py-1">Kurs</th><th class="py-1">Ergebnis</th><th class="py-1">Datum</th></tr></thead>
                    <tbody>
                        @forelse ($examResults as $session)
                            <tr class="border-t border-slate-100 dark:border-slate-700">
                                <td class="py-1.5">{{ $session->course->name }}</td>
                                <td class="py-1.5">
                                    <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full {{ $session->passed ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">
                                        {{ (int) round($session->score * 100) }}% &middot; {{ $session->passed ? 'Bestanden' : 'Nicht bestanden' }}
                                    </span>
                                    @if ($session->openTextCount > 0)
                                        {{-- Bug-Review (FKN offene Fragen): siehe exam/result.blade.php. --}}
                                        <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{{ $session->openTextCorrect }}/{{ $session->openTextCount }} Punkte durch Selbsteinschätzung</div>
                                    @endif
                                </td>
                                <td class="py-1.5 text-slate-500 dark:text-slate-400">{{ \App\Support\GermanTime::format($session->submitted_at, 'd.m.Y H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="py-3 text-slate-500 dark:text-slate-400">Noch keine Prüfungssimulation abgelegt.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</x-superadmin-layout>
