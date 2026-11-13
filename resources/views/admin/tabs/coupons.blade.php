{{-- Bug-Review T8: der restliche Admin-Bereich siezt durchgehend (siehe
     die zahlreichen "Du/Sie im Admin-Bereich gemischt"-Fixes), dieser
     Absatz nutzte bisher als einzige Stelle hier die informelle
     ihr-Form. --}}
<p class="text-slate-500 dark:text-slate-400 mb-6">
    Eigene Coupon-Codes bekommen Sie auf drei Wegen: Bestellungen im verknüpften Shop werden automatisch
    gutgeschrieben, der Plattform-Betreiber teilt Ihnen welche zu, oder Sie übernehmen anderswo gekaufte Codes per
    Import in Ihren Bestand.
</p>

<div class="grid lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4">
        <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-1 flex items-center gap-1.5">
            <x-icon name="upload" class="w-4 h-4 text-slate-600 dark:text-slate-400" /> Codes importieren
        </h3>
        <p class="text-xs text-slate-600 dark:text-slate-400 mb-3">
            Ein Code pro Zeile (oder durch Komma/Semikolon getrennt). Kurs oder Produkt erkennt das System
            automatisch anhand des Codes -- eine Auswahl ist nicht nötig.
        </p>
        <form method="POST" action="{{ route('admin.coupons.import') }}" class="space-y-3">
            @csrf
            <div>
                <label for="codes" class="sr-only">Coupon-Codes</label>
                <textarea id="codes" name="codes" rows="4" required placeholder="XXXX-XXXX-XXXX-XXXX" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm font-mono">{{ old('codes') }}</textarea>
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
        <div class="overflow-x-auto">
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
                        <td class="px-4 py-2 text-slate-500 dark:text-slate-400">{{ $row->used }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-3 text-slate-500 dark:text-slate-400">
                        Noch keine eigenen Codes vorhanden.
                        <span class="block text-xs mt-1">Importieren Sie gekaufte Codes über „Codes importieren“ oder bestellen Sie Codes im verknüpften Shop – sie erscheinen dann hier.</span>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>

{{-- Shop-Anbindung (docs/commerce/README.md): Codes aus Bestellungen im
     B2B-Shop landen direkt hier im Bestand. --}}
<div class="grid lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4">
        <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-1 flex items-center gap-1.5">
            <x-icon name="link" class="w-4 h-4 text-slate-600 dark:text-slate-400" /> Shop verknüpfen
        </h3>
        <p class="text-xs text-slate-600 dark:text-slate-400 mb-3">
            Codes, die Sie im Shop kaufen, werden nach der Zahlung automatisch diesem Bestand gutgeschrieben. Dafür
            einmal das Shopkonto mit Ihrer Bootsschule verknüpfen: Code erzeugen und im Shop unter
            „Mein Konto → Bootsschule verknüpfen“ eingeben. Der Code gilt {{ config('commerce.link_code_minutes', 15) }} Minuten.
        </p>
        @if ($shopNeedsLink ?? false)
            <p class="mb-3 rounded-lg bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 p-3 text-xs text-amber-800 dark:text-amber-200">
                Im Shop gibt es bereits ein Kundenkonto mit Ihrer E-Mail-Adresse. Aus Sicherheitsgründen wurde es nicht automatisch
                verknüpft: Bitte erzeugen Sie hier einen Code und geben Sie ihn im Shop ein.
            </p>
        @endif
        @if (session('shopLinkCode'))
            <div class="mb-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 p-3" role="status">
                <p class="text-xs text-emerald-800 dark:text-emerald-200">Ihr Verknüpfungscode (wird nur einmal angezeigt):</p>
                <p class="font-mono text-lg font-semibold tracking-wider text-emerald-900 dark:text-emerald-100">{{ session('shopLinkCode') }}</p>
            </div>
        @endif
        <form method="POST" action="{{ route('admin.shop-link.code') }}">
            @csrf
            <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                Verknüpfungscode erzeugen
            </button>
        </form>
        @if ($shopAccounts->isNotEmpty())
            <ul class="mt-4 divide-y divide-slate-100 dark:divide-slate-700 text-sm">
                @foreach ($shopAccounts as $shopAccount)
                    <li class="py-2 flex items-center justify-between gap-3">
                        <span class="text-slate-700 dark:text-slate-200">
                            {{ $shopAccount->email }}@if ($shopAccount->company_name) · {{ $shopAccount->company_name }}@endif
                            <span class="block text-xs text-slate-500 dark:text-slate-400">verknüpft am {{ \App\Support\GermanTime::format($shopAccount->linked_at, 'd.m.Y') }}</span>
                            @unless ($shopAccount->purchase_possible)
                                <span class="block text-xs text-amber-700 dark:text-amber-300">Einkauf derzeit nicht möglich: Die Person, die den Code erzeugt hat, ist nicht mehr Inhaber oder Admin. Bitte Verknüpfung lösen und neu verknüpfen.</span>
                            @endunless
                        </span>
                        <form method="POST" action="{{ route('admin.shop-link.unlink', $shopAccount->id) }}" onsubmit="return confirm('Verknüpfung mit diesem Shopkonto lösen? Danach sind keine Einkäufe für die Bootsschule mehr möglich.')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-xs text-red-600 hover:underline">Verknüpfung lösen</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100 dark:border-slate-700">
            <h3 class="font-medium text-slate-700 dark:text-slate-200">Bestellungen aus dem Shop</h3>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 dark:bg-slate-700 text-slate-500 dark:text-slate-300 text-left">
                <tr>
                    <th class="px-4 py-2">Bestellung</th>
                    <th class="px-4 py-2">Kurs / Produkt</th>
                    <th class="px-4 py-2">Codes</th>
                    <th class="px-4 py-2">Datum</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($shopBatches as $batch)
                    <tr class="border-t border-slate-100 dark:border-slate-700">
                        <td class="px-4 py-2 text-slate-700 dark:text-slate-200">{{ $batch->order_number ?? $batch->order_id }}</td>
                        <td class="px-4 py-2 text-slate-700 dark:text-slate-200">
                            {{ $batch->course->name ?? $batch->product->name ?? '—' }}@if ($batch->kind === 'renewal') (Verlängerung)@endif
                        </td>
                        <td class="px-4 py-2 text-slate-600 dark:text-slate-300">
                            {{ $batch->quantity }}, davon {{ $batch->redeemed_count }} eingelöst
                            @if ($batch->status !== 'delivered')
                                <span class="block text-xs text-amber-700 dark:text-amber-300">{{ $batch->status === 'voided' ? 'storniert' : 'teilweise storniert' }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-slate-500 dark:text-slate-400">{{ \App\Support\GermanTime::format($batch->created_at, 'd.m.Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-3 text-slate-500 dark:text-slate-400">
                        Noch keine Bestellungen aus dem Shop.
                        <span class="block text-xs mt-1">
                            @if ($shopAccounts->isEmpty())
                                Verknüpfen Sie zuerst Ihr Shopkonto („Verknüpfungscode erzeugen“) – Bestellungen und ihre Codes erscheinen danach automatisch hier.
                            @else
                                Sobald Sie im verknüpften Shop Codes bestellen, erscheinen die Bestellungen nach der Zahlung hier.
                            @endif
                        </span>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>

<div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
    <form method="GET" action="{{ route('admin.dashboard') }}" class="flex flex-wrap gap-2 p-4 border-b border-slate-100 dark:border-slate-700">
        <input type="hidden" name="tab" value="coupons">
        <label for="coupon_search" class="sr-only">Code, Kurs/Produkt oder Teilnehmer suchen</label>
        <input type="text" id="coupon_search" name="coupon_search" value="{{ $couponFilters['coupon_search'] ?? '' }}"
               placeholder="Code, Kurs/Produkt oder Teilnehmer suchen…"
               class="flex-1 min-w-[220px] rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
        <label for="coupon_status" class="sr-only">Status filtern</label>
        <select id="coupon_status" name="coupon_status" @change="$el.form.submit()" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
            <option value="">Alle Status</option>
            <option value="open" @selected(($couponFilters['coupon_status'] ?? null) === 'open')>offen</option>
            <option value="redeemed" @selected(($couponFilters['coupon_status'] ?? null) === 'redeemed')>eingelöst</option>
            <option value="voided" @selected(($couponFilters['coupon_status'] ?? null) === 'voided')>gesperrt</option>
            <option value="expired" @selected(($couponFilters['coupon_status'] ?? null) === 'expired')>abgelaufen</option>
        </select>
        <label for="coupon_type" class="sr-only">Typ filtern</label>
        <select id="coupon_type" name="coupon_type" @change="$el.form.submit()" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
            <option value="">Kurse, Produkte &amp; Verlängerungen</option>
            <option value="course" @selected(($couponFilters['coupon_type'] ?? null) === 'course')>nur Kurse</option>
            <option value="product" @selected(($couponFilters['coupon_type'] ?? null) === 'product')>nur Produkte</option>
            <option value="renewal" @selected(($couponFilters['coupon_type'] ?? null) === 'renewal')>nur Verlängerungscodes</option>
        </select>
        <button type="submit" class="px-4 py-2 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 transition text-sm">Suchen</button>
        @if (($couponFilters['coupon_search'] ?? '') !== '' || ($couponFilters['coupon_status'] ?? '') !== '' || ($couponFilters['coupon_type'] ?? '') !== '')
            <a href="{{ route('admin.dashboard', ['tab' => 'coupons']) }}" class="text-sm text-slate-500 dark:text-slate-400 hover:underline self-center">Filter zurücksetzen</a>
        @endif
    </form>
    {{-- Bug-Review (/admin zu schwer): die Nutzerliste für "Zuweisen" stand
         bisher komplett innerhalb der @forelse-Schleife unten -- bei 500
         Lernenden und 50 Coupons auf einer Seite ergab das 50 identische
         <select>-Listen mit je 500 <option>-Elementen (25.000+ insgesamt,
         4,8 MB HTML). Die Optionen stehen jetzt nur noch EINMAL in dieser
         versteckten Vorlage (außerhalb der <table>, da <select> dort kein
         gültiges Kindelement ist) und werden per Skript unten in jedes
         <select> der Zeilen geklont, statt sie serverseitig zu duplizieren. --}}
    @if ($coupons->contains(fn ($c) => $c->isUsable() && $c->tenant_id === $tenant->id))
        <select id="coupon-assign-options-template" hidden aria-hidden="true">
            <option value="">Teilnehmer wählen…</option>
            @foreach ($allParticipants as $membership)
                <option value="{{ $membership->user_id }}">{{ $membership->user->name }}</option>
            @endforeach
        </select>
    @endif
    <div class="overflow-x-auto">
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
                        <span class="inline-block px-2 py-0.5 rounded-full text-xs {{ $coupon->isForProduct() ? 'bg-indigo-50 text-indigo-700' : ($coupon->isForRenewal() ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600') }}">
                            {{ $coupon->isForProduct() ? 'Produkt' : ($coupon->isForRenewal() ? 'Verlängerung' : 'Kurs') }}
                        </span>
                    </td>
                    <td class="px-4 py-2 text-slate-600 dark:text-slate-300">{{ $coupon->redeemableName() }}</td>
                    <td class="px-4 py-2">
                        @if ($coupon->isUsable() && isset($reservedCouponCodes[$coupon->code]))
                            <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200" title="Wird eingelöst, sobald die eingeladene Person die Einladung annimmt.">reserviert für Einladung</span>
                        @elseif ($coupon->isUsable())
                            <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700">offen{{ $coupon->expires_at ? ' · bis '.\App\Support\GermanTime::format($coupon->expires_at, 'd.m.Y') : '' }}</span>
                        @else
                            <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full {{ $coupon->isRedeemed() ? 'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400' : 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200' }}">{{ $coupon->statusLabel() }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-slate-500 dark:text-slate-400">{{ $coupon->redeemedBy?->name ?? '—' }}</td>
                    <td class="px-4 py-2 text-slate-500 dark:text-slate-400 text-xs">{{ \App\Support\GermanTime::format($coupon->redeemed_at, 'd.m.Y H:i') ?? '—' }}</td>
                    <td class="px-4 py-2">
                        @if ($coupon->isUsable() && $coupon->tenant_id === $tenant->id && ! isset($reservedCouponCodes[$coupon->code]))
                            <form method="POST" action="{{ route('admin.coupons.assign', $coupon) }}" class="flex gap-1">
                                @csrf
                                <select name="user_id" required aria-label="Nutzer für Coupon {{ $coupon->code }} wählen" class="js-coupon-assign-select rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-xs">
                                    <option value="">Teilnehmer wählen…</option>
                                </select>
                                <button type="submit" class="px-3 py-1 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 transition text-xs whitespace-nowrap">Zuweisen</button>
                            </form>
                        @else
                            <span class="text-slate-300 dark:text-slate-600">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-3 text-slate-500 dark:text-slate-400">
                    @if (array_filter($couponFilters))
                        Keine Codes gefunden, die zu diesem Filter passen.
                    @else
                        Noch keine Codes für diese Bootsschule.
                        <span class="block text-xs mt-1">Importieren Sie vorhandene Codes über „Codes importieren“ oder bestellen Sie neue im verknüpften Shop. Codes, die Ihnen der Plattform-Betreiber zuteilt, erscheinen hier automatisch.</span>
                    @endif
                </td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
    <div class="p-4">{{ $coupons->links() }}</div>
</div>

<script nonce="{{ $cspNonce }}">
    (function () {
        const source = document.getElementById('coupon-assign-options-template');
        if (! source) {
            return;
        }
        document.querySelectorAll('.js-coupon-assign-select').forEach((select) => {
            select.innerHTML = source.innerHTML;
        });
    })();
</script>

@if ($errors->has('assign'))
    @php(\App\Support\ShownFormErrors::register([$errors->first('assign')]))
    <p class="text-rose-600 text-sm mt-3">{{ $errors->first('assign') }}</p>
@endif
