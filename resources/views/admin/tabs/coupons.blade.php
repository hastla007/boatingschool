<p class="text-slate-500 mb-6">
    Eigene Coupon-Codes bekommt ihr auf zwei Wegen: entweder teilt euch der Plattform-Betreiber welche zu, oder ihr
    übernehmt Codes, die ihr einzeln oder im Bulk über einen externen Webshop gekauft habt, per Import in euren
    Bestand.
</p>

<div class="grid lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4">
        <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-1 flex items-center gap-1.5">
            <x-icon name="upload" class="w-4 h-4 text-slate-400" /> Codes importieren
        </h3>
        <p class="text-xs text-slate-400 mb-3">
            Ein Code pro Zeile (oder durch Komma/Semikolon getrennt). Kurs oder Produkt erkennt das System
            automatisch anhand des Codes -- eine Auswahl ist nicht nötig.
        </p>
        <form method="POST" action="{{ route('admin.coupons.import') }}" class="space-y-3">
            @csrf
            <div>
                <textarea name="codes" rows="4" required placeholder="XXXX-XXXX-XXXX" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm font-mono">{{ old('codes') }}</textarea>
                <x-input-error :messages="$errors->get('codes')" class="mt-2" />
            </div>
            <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                Importieren
            </button>
        </form>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100 dark:border-slate-700">
            <h3 class="font-medium text-slate-700 dark:text-slate-200">Bestand je Kurs/Produkt</h3>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-slate-50 dark:bg-slate-700 text-slate-500 dark:text-slate-300 text-left">
                <tr>
                    <th class="px-4 py-2">Kurs / Produkt</th>
                    <th class="px-4 py-2">Frei</th>
                    <th class="px-4 py-2">Verbraucht</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($couponSummary as $row)
                    <tr class="border-t border-slate-100 dark:border-slate-700">
                        <td class="px-4 py-2 text-slate-700 dark:text-slate-200">{{ $row->name }}</td>
                        <td class="px-4 py-2 text-emerald-600 font-medium">{{ $row->free }}</td>
                        <td class="px-4 py-2 text-slate-500">{{ $row->used }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-3 text-slate-500">Noch keine eigenen Codes vorhanden.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
    <form method="GET" action="{{ route('admin.dashboard') }}" class="flex flex-wrap gap-2 p-4 border-b border-slate-100 dark:border-slate-700">
        <input type="hidden" name="tab" value="coupons">
        <input type="text" name="coupon_search" value="{{ $couponFilters['coupon_search'] ?? '' }}"
               placeholder="Code, Kurs/Produkt oder Nutzer suchen…"
               class="flex-1 min-w-[220px] rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
        <select name="coupon_status" onchange="this.form.submit()" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
            <option value="">Alle Status</option>
            <option value="open" @selected(($couponFilters['coupon_status'] ?? null) === 'open')>offen</option>
            <option value="redeemed" @selected(($couponFilters['coupon_status'] ?? null) === 'redeemed')>eingelöst</option>
        </select>
        <select name="coupon_type" onchange="this.form.submit()" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
            <option value="">Kurse &amp; Produkte</option>
            <option value="course" @selected(($couponFilters['coupon_type'] ?? null) === 'course')>nur Kurse</option>
            <option value="product" @selected(($couponFilters['coupon_type'] ?? null) === 'product')>nur Produkte</option>
        </select>
        <button type="submit" class="px-4 py-2 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 transition text-sm">Suchen</button>
        @if (($couponFilters['coupon_search'] ?? '') !== '' || ($couponFilters['coupon_status'] ?? '') !== '' || ($couponFilters['coupon_type'] ?? '') !== '')
            <a href="{{ route('admin.dashboard', ['tab' => 'coupons']) }}" class="text-sm text-slate-500 hover:underline self-center">Filter zurücksetzen</a>
        @endif
    </form>
    <table class="w-full text-sm">
        <thead class="bg-slate-50 dark:bg-slate-700 text-slate-500 dark:text-slate-300 text-left">
            <tr>
                <th class="px-4 py-2">Code</th>
                <th class="px-4 py-2">Typ</th>
                <th class="px-4 py-2">Kurs / Produkt</th>
                <th class="px-4 py-2">Status</th>
                <th class="px-4 py-2">Eingelöst von</th>
                <th class="px-4 py-2">Eingelöst am</th>
                <th class="px-4 py-2">Zuweisen</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($coupons as $coupon)
                <tr class="border-t border-slate-100 dark:border-slate-700">
                    <td class="px-4 py-2 font-mono text-xs">{{ $coupon->code }}</td>
                    <td class="px-4 py-2">
                        <span class="inline-block px-2 py-0.5 rounded-full text-xs {{ $coupon->isForProduct() ? 'bg-indigo-50 text-indigo-700' : 'bg-slate-100 text-slate-600' }}">
                            {{ $coupon->isForProduct() ? 'Produkt' : 'Kurs' }}
                        </span>
                    </td>
                    <td class="px-4 py-2 text-slate-600 dark:text-slate-300">{{ $coupon->redeemableName() }}</td>
                    <td class="px-4 py-2">
                        @if ($coupon->isRedeemed())
                            <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-500">eingelöst</span>
                        @else
                            <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700">offen</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-slate-500">{{ $coupon->redeemedBy?->name ?? '—' }}</td>
                    <td class="px-4 py-2 text-slate-500 text-xs">{{ $coupon->redeemed_at?->format('d.m.Y H:i') ?? '—' }}</td>
                    <td class="px-4 py-2">
                        @if (! $coupon->isRedeemed() && $coupon->tenant_id === $tenant->id)
                            <form method="POST" action="{{ route('admin.coupons.assign', $coupon) }}" class="flex gap-1">
                                @csrf
                                <select name="user_id" required class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-xs">
                                    <option value="">Nutzer wählen…</option>
                                    @foreach ($participants as $membership)
                                        <option value="{{ $membership->user_id }}">{{ $membership->user->name }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="px-3 py-1 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 transition text-xs whitespace-nowrap">Zuweisen</button>
                            </form>
                        @else
                            <span class="text-slate-300">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-3 text-slate-500">
                    @if (array_filter($couponFilters))
                        Keine Codes gefunden, die zu diesem Filter passen.
                    @else
                        Noch keine Codes für diese Bootsschule.
                    @endif
                </td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="p-4">{{ $coupons->links() }}</div>
</div>

@if ($errors->has('assign'))
    <p class="text-rose-600 text-sm mt-3">{{ $errors->first('assign') }}</p>
@endif
