<x-superadmin-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Coupon-Codes</h1>
    </x-slot>

    <div class="grid lg:grid-cols-3 gap-4 mb-6">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Codes erzeugen</h3>
            <form method="POST" action="{{ route('superadmin.coupons.store') }}" class="space-y-3"
                  x-data="{ target: {{ json_encode(old('target', ''), JSON_HEX_APOS | JSON_HEX_QUOT) }} }">
                @csrf
                <div>
                    <x-input-label for="target" value="Kurs oder Produkt" />
                    <select id="target" name="target" x-model="target" required class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                        <optgroup label="Kurse">
                            @foreach ($courses as $course)
                                <option value="course:{{ $course->id }}">{{ $course->name }}</option>
                            @endforeach
                        </optgroup>
                        <optgroup label="Produkte">
                            @foreach ($products as $product)
                                <option value="product:{{ $product->id }}">{{ $product->name }}</option>
                            @endforeach
                        </optgroup>
                        <optgroup label="Verlängerungscodes">
                            @foreach ($courses as $course)
                                <option value="renewal:{{ $course->id }}">{{ $course->name }} (Verlängerung)</option>
                            @endforeach
                        </optgroup>
                    </select>
                    <x-input-error :messages="$errors->get('target')" class="mt-2" />
                </div>
                @php($selectedOptionProductIds = old('option_product_ids', []))
                <div x-show="target.startsWith('course:')" style="display: none">
                    <x-input-label value="Optionen (optional)" />
                    <div class="mt-1 space-y-1.5 border border-slate-200 dark:border-slate-600 rounded-lg p-3">
                        @foreach ($products as $product)
                            <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                                <input type="checkbox" name="option_product_ids[]" value="{{ $product->id }}"
                                       @checked(in_array($product->id, $selectedOptionProductIds, true))
                                       class="rounded border-slate-300 dark:bg-slate-700 dark:border-slate-600">
                                {{ $product->name }}
                            </label>
                        @endforeach
                    </div>
                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Werden bei Einlösung zusätzlich zum Kurs als eigene Buchungen freigeschaltet.</p>
                    <x-input-error :messages="$errors->get('option_product_ids')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="tenant_id" value="Bootsschule (optional)" />
                    <select id="tenant_id" name="tenant_id" class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                        <option value="">&mdash; frei einlösbar, keiner Bootsschule zugeordnet &mdash;</option>
                        @foreach ($tenants as $tenant)
                            <option value="{{ $tenant->id }}" @selected(old('tenant_id', request('tenant_id')) === $tenant->id)>{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Ist eine Bootsschule gewählt, kann der Code nur dort eingelöst werden.</p>
                </div>
                <div>
                    <x-input-label for="quantity" value="Anzahl (Batch-Generierung)" />
                    <x-text-input id="quantity" name="quantity" type="number" min="1" max="500" class="mt-1 block w-full" :value="old('quantity', 1)" required />
                    <x-input-error :messages="$errors->get('quantity')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="batch_label" value="Bezeichnung (optional)" />
                    <x-text-input id="batch_label" name="batch_label" type="text" class="mt-1 block w-full" :value="old('batch_label')" placeholder="z. B. Kontingent Herbst 2026" />
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90 transition">Code(s) erzeugen</button>
            </form>
        </div>

        {{-- Bug-Review R4-2: @change/@submit auf den Filter-Selects und dem
             Export-Formular lagen bisher außerhalb JEDES x-data-Bereichs
             (das einzige x-data auf dieser Seite gehört zum Codes-erzeugen-
             Formular links) -- Alpine initialisiert Direktiven nur innerhalb
             eines x-data-Teilbaums, ohne ihn blieben die @change-Handler
             komplett wirkungslos (Filter reagierten nicht auf Auswahl,
             Alle-auswählen/Export-Button taten nichts). --}}
        <div class="lg:col-span-2 bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5" x-data="{}">
            <form method="GET" class="flex flex-wrap gap-2 mb-4">
                <label for="coupon_search_sa" class="sr-only">Code, Kurs/Produkt, Bootsschule oder Nutzer suchen</label>
                <input type="text" id="coupon_search_sa" name="search" value="{{ $filters['search'] ?? '' }}"
                       placeholder="Code, Kurs/Produkt, Bootsschule oder Nutzer suchen…"
                       class="flex-1 min-w-[220px] rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                <label for="coupon_tenant_id_sa" class="sr-only">Bootsschule filtern</label>
                <select id="coupon_tenant_id_sa" name="tenant_id" @change="$el.form.submit()" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                    <option value="">Alle Bootsschulen</option>
                    @foreach ($tenants as $tenant)
                        <option value="{{ $tenant->id }}" @selected(($filters['tenant_id'] ?? null) === $tenant->id)>{{ $tenant->name }}</option>
                    @endforeach
                </select>
                <label for="coupon_status_sa" class="sr-only">Status filtern</label>
                <select id="coupon_status_sa" name="status" @change="$el.form.submit()" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                    <option value="">Alle Status</option>
                    <option value="open" @selected(($filters['status'] ?? null) === 'open')>offen</option>
                    <option value="redeemed" @selected(($filters['status'] ?? null) === 'redeemed')>eingelöst</option>
                </select>
                <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 transition text-sm">Suchen</button>
                @if (! empty($filters['tenant_id']) || ! empty($filters['status']) || ! empty($filters['search']))
                    <a href="{{ route('superadmin.coupons.index') }}" class="text-sm text-slate-500 hover:underline self-center">Filter zurücksetzen</a>
                @endif
            </form>

            <form method="POST" action="{{ route('superadmin.coupons.export') }}" id="export-form">
                @csrf
                <input type="hidden" name="tenant_id" value="{{ $filters['tenant_id'] ?? '' }}">
                <input type="hidden" name="status" value="{{ $filters['status'] ?? '' }}">
                <input type="hidden" name="search" value="{{ $filters['search'] ?? '' }}">
                <input type="hidden" name="select_all" id="select-all-input" value="0">

                <div class="flex items-center justify-between mb-2">
                    <label class="flex items-center gap-2 text-sm text-slate-500">
                        <input type="checkbox" id="select-all-checkbox" @change="couponExport.toggleSelectAll($el.checked)">
                        Alle {{ $coupons->total() }} gefilterten Codes auswählen
                    </label>
                    <button type="submit" id="export-selected-btn" disabled
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 transition text-sm disabled:opacity-40 disabled:cursor-not-allowed">
                        <x-icon name="download" class="w-4 h-4" /> Ausgewählte exportieren (.txt)
                    </button>
                </div>

                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-slate-600 dark:text-slate-400 text-left">
                        <tr>
                            <th class="py-1 w-6"></th>
                            <th class="py-1">Code</th>
                            <th class="py-1">Typ</th>
                            <th class="py-1">Kurs / Produkt</th>
                            <th class="py-1">Bootsschule</th>
                            <th class="py-1">Erzeugt am</th>
                            <th class="py-1">Status</th>
                            <th class="py-1">Eingelöst am</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($coupons as $coupon)
                            <tr class="border-t border-slate-100 dark:border-slate-700">
                                <td class="py-1.5">
                                    <input type="checkbox" name="ids[]" value="{{ $coupon->id }}" class="coupon-row-checkbox" @change="couponExport.updateButton()">
                                </td>
                                <td class="py-1.5 font-mono text-xs">{{ $coupon->code }}</td>
                                <td class="py-1.5 text-slate-500">
                                    <span class="inline-block px-2 py-0.5 rounded-full text-xs {{ $coupon->isForProduct() ? 'bg-indigo-50 text-indigo-700' : ($coupon->isForRenewal() ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600') }}">
                                        {{ $coupon->isForProduct() ? 'Produkt' : ($coupon->isForRenewal() ? 'Verlängerung' : 'Kurs') }}
                                    </span>
                                </td>
                                <td class="py-1.5 text-slate-600 dark:text-slate-300">
                                    {{ $coupon->redeemableName() }}
                                    @if ($coupon->optionProducts->isNotEmpty())
                                        <span class="block text-xs text-indigo-600 dark:text-indigo-400">+ {{ $coupon->optionProducts->pluck('name')->implode(', ') }}</span>
                                    @endif
                                </td>
                                <td class="py-1.5 text-slate-500">{{ $coupon->tenant?->name ?? '—' }}</td>
                                <td class="py-1.5 text-slate-500 text-xs">{{ \App\Support\GermanTime::format($coupon->created_at, 'd.m.Y H:i') ?? '—' }}</td>
                                <td class="py-1.5">
                                    @if ($coupon->isRedeemed())
                                        <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-500">
                                            eingelöst &middot; {{ $coupon->redeemedBy?->name }} ({{ $coupon->redeemedTenant?->name }})
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700">offen</span>
                                    @endif
                                </td>
                                <td class="py-1.5 text-slate-500 text-xs">{{ \App\Support\GermanTime::format($coupon->redeemed_at, 'd.m.Y H:i') ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="py-3 text-slate-500">Keine Codes gefunden.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </form>
            <div class="mt-4">{{ $coupons->links() }}</div>
        </div>
    </div>

    <script nonce="{{ $cspNonce }}">
        window.couponExport = {
            toggleSelectAll(checked) {
                document.getElementById('select-all-input').value = checked ? '1' : '0';
                document.querySelectorAll('.coupon-row-checkbox').forEach((el) => {
                    el.checked = checked;
                    el.disabled = checked;
                });
                this.updateButton();
            },
            updateButton() {
                const selectAll = document.getElementById('select-all-checkbox').checked;
                const anyChecked = selectAll || Array.from(document.querySelectorAll('.coupon-row-checkbox')).some((el) => el.checked);
                document.getElementById('export-selected-btn').disabled = !anyChecked;
            },
        };
    </script>
</x-superadmin-layout>
