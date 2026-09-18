<x-superadmin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Coupon-Codes</h2>
    </x-slot>

    <div class="grid lg:grid-cols-3 gap-4 mb-6">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Codes erzeugen</h3>
            <form method="POST" action="{{ route('superadmin.coupons.store') }}" class="space-y-3">
                @csrf
                <div>
                    <x-input-label for="target" value="Kurs oder Produkt" />
                    <select id="target" name="target" required class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
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
                    </select>
                    <x-input-error :messages="$errors->get('target')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="tenant_id" value="Bootsschule (optional)" />
                    <select id="tenant_id" name="tenant_id" class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                        <option value="">&mdash; frei einlösbar, keiner Bootsschule zugeordnet &mdash;</option>
                        @foreach ($tenants as $tenant)
                            <option value="{{ $tenant->id }}" @selected(old('tenant_id', request('tenant_id')) === $tenant->id)>{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-slate-400 mt-1">Ist eine Bootsschule gewählt, kann der Code nur dort eingelöst werden.</p>
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

        <div class="lg:col-span-2 bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
            <form method="GET" class="flex flex-wrap gap-2 mb-4">
                <select name="tenant_id" onchange="this.form.submit()" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                    <option value="">Alle Bootsschulen</option>
                    @foreach ($tenants as $tenant)
                        <option value="{{ $tenant->id }}" @selected(($filters['tenant_id'] ?? null) === $tenant->id)>{{ $tenant->name }}</option>
                    @endforeach
                </select>
                <select name="status" onchange="this.form.submit()" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                    <option value="">Alle Status</option>
                    <option value="open" @selected(($filters['status'] ?? null) === 'open')>offen</option>
                    <option value="redeemed" @selected(($filters['status'] ?? null) === 'redeemed')>eingelöst</option>
                </select>
                @if (! empty($filters['tenant_id']) || ! empty($filters['status']))
                    <a href="{{ route('superadmin.coupons.index') }}" class="text-sm text-slate-500 hover:underline self-center">Filter zurücksetzen</a>
                @endif
            </form>

            <table class="w-full text-sm">
                <thead class="text-slate-400 text-left">
                    <tr>
                        <th class="py-1">Code</th>
                        <th class="py-1">Typ</th>
                        <th class="py-1">Kurs / Produkt</th>
                        <th class="py-1">Bootsschule</th>
                        <th class="py-1">Status</th>
                        <th class="py-1">Eingelöst am</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($coupons as $coupon)
                        <tr class="border-t border-slate-100 dark:border-slate-700">
                            <td class="py-1.5 font-mono text-xs">{{ $coupon->code }}</td>
                            <td class="py-1.5 text-slate-500">
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs {{ $coupon->isForProduct() ? 'bg-indigo-50 text-indigo-700' : 'bg-slate-100 text-slate-600' }}">
                                    {{ $coupon->isForProduct() ? 'Produkt' : 'Kurs' }}
                                </span>
                            </td>
                            <td class="py-1.5 text-slate-600 dark:text-slate-300">{{ $coupon->redeemableName() }}</td>
                            <td class="py-1.5 text-slate-500">{{ $coupon->tenant?->name ?? '—' }}</td>
                            <td class="py-1.5">
                                @if ($coupon->isRedeemed())
                                    <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-500">
                                        eingelöst &middot; {{ $coupon->redeemedBy?->name }} ({{ $coupon->redeemedTenant?->name }})
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700">offen</span>
                                @endif
                            </td>
                            <td class="py-1.5 text-slate-500 text-xs">{{ $coupon->redeemed_at?->format('d.m.Y H:i') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-3 text-slate-500">Keine Codes gefunden.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="mt-4">{{ $coupons->links() }}</div>
        </div>
    </div>
</x-superadmin-layout>
