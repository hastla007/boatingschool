<x-superadmin-layout>
    <x-slot name="header">
        <div>
            <div class="text-xs text-slate-600 dark:text-slate-400"><a href="{{ route('superadmin.users.index') }}" class="hover:underline">Nutzer</a></div>
            <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">{{ $user->name }}</h2>
        </div>
    </x-slot>

    <div class="grid lg:grid-cols-3 gap-4 mb-6">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 lg:col-span-1">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Stammdaten</h3>
            <form method="POST" action="{{ route('superadmin.users.update', $user) }}" class="space-y-3">
                @csrf
                @method('PATCH')

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <x-input-label for="first_name" value="Vorname" />
                        <x-text-input id="first_name" name="first_name" type="text" class="mt-1 block w-full" :value="old('first_name', $user->first_name)" required />
                    </div>
                    <div>
                        <x-input-label for="last_name" value="Nachname" />
                        <x-text-input id="last_name" name="last_name" type="text" class="mt-1 block w-full" :value="old('last_name', $user->last_name)" required />
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
                        @foreach (['invited', 'active', 'suspended', 'closed'] as $option)
                            <option value="{{ $option }}" @selected(old('status', $user->status) === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300 pt-1">
                    <input type="checkbox" name="is_superadmin" value="1" @checked(old('is_superadmin', $user->is_superadmin)) class="rounded border-slate-300">
                    Superadmin (plattformweiter Zugriff)
                </label>

                <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90 transition">Speichern</button>
            </form>
        </div>

        <div class="lg:col-span-2 space-y-4">
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
                <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
                    <x-icon name="swatch" class="w-4 h-4 text-slate-600 dark:text-slate-400" /> Bootsschul-Zuordnungen
                </h3>
                <div class="space-y-2 text-sm mb-4">
                    @forelse ($memberships as $membership)
                        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-700 pb-2 last:border-0">
                            <a href="{{ route('superadmin.tenants.show', $membership->tenant) }}" class="text-slate-700 dark:text-slate-200 hover:underline">{{ $membership->tenant->name }}</a>
                            <span class="inline-block px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-xs">{{ $membership->role }}</span>
                        </div>
                    @empty
                        <p class="text-slate-500">Noch keine Bootsschule zugeordnet.</p>
                    @endforelse
                </div>
                <form method="POST" action="{{ route('superadmin.users.memberships.store', $user) }}" class="flex gap-2">
                    @csrf
                    <label for="membership_tenant_id" class="sr-only">Bootsschule</label>
                    <select id="membership_tenant_id" name="tenant_id" required class="flex-1 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-xs">
                        @foreach ($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                    <label for="membership_role" class="sr-only">Rolle</label>
                    <select id="membership_role" name="role" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-xs">
                        @foreach (['learner', 'staff', 'instructor', 'admin', 'owner', 'support'] as $role)
                            <option value="{{ $role }}">{{ $role }}</option>
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
                                <td class="py-1.5 text-slate-500">{{ $result['entitlement']->tenant->name }}</td>
                                <td class="py-1.5 text-slate-500">{{ $result['percent'] }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="py-3 text-slate-500">Noch keine Kurszugänge.</td></tr>
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
                                <td class="py-1.5 text-slate-500">{{ $purchase->tenant->name }}</td>
                                <td class="py-1.5 text-slate-500">{{ $purchase->source_type === 'coupon' ? 'Coupon '.$purchase->source_reference : $purchase->source_type }}</td>
                                <td class="py-1.5 text-slate-500">{{ \App\Support\GermanTime::format($purchase->created_at, 'd.m.Y H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-3 text-slate-500">Noch keine Produkte gekauft.</td></tr>
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
                                        <div class="text-xs text-slate-500 mt-0.5">{{ $session->openTextCorrect }}/{{ $session->openTextCount }} Punkte durch Selbsteinschätzung</div>
                                    @endif
                                </td>
                                <td class="py-1.5 text-slate-500">{{ \App\Support\GermanTime::format($session->submitted_at, 'd.m.Y H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="py-3 text-slate-500">Noch keine Prüfungssimulation abgelegt.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</x-superadmin-layout>
