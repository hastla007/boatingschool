{{-- Bug-Review T8: der restliche Admin-Bereich siezt durchgehend (siehe
     die zahlreichen "Du/Sie im Admin-Bereich gemischt"-Fixes), dieser
     Absatz nutzte bisher als einzige Stelle hier die informelle
     ihr-Form. --}}
<p class="text-slate-500 dark:text-slate-400 mb-6">
    Eigene Coupon-Codes bekommen Sie auf zwei Wegen: entweder teilt Ihnen der Plattform-Betreiber welche zu, oder Sie
    übernehmen Codes, die Sie einzeln oder im Bulk über einen externen Webshop gekauft haben, per Import in Ihren
    Bestand.
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
                <textarea id="codes" name="codes" rows="4" required placeholder="XXXX-XXXX-XXXX" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm font-mono">{{ old('codes') }}</textarea>
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
                    <tr><td colspan="3" class="px-4 py-3 text-slate-500 dark:text-slate-400">Noch keine eigenen Codes vorhanden.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>

<div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
    <form method="GET" action="{{ route('admin.dashboard') }}" class="flex flex-wrap gap-2 p-4 border-b border-slate-100 dark:border-slate-700">
        <input type="hidden" name="tab" value="coupons">
        <label for="coupon_search" class="sr-only">Code, Kurs/Produkt oder Nutzer suchen</label>
        <input type="text" id="coupon_search" name="coupon_search" value="{{ $couponFilters['coupon_search'] ?? '' }}"
               placeholder="Code, Kurs/Produkt oder Nutzer suchen…"
               class="flex-1 min-w-[220px] rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
        <label for="coupon_status" class="sr-only">Status filtern</label>
        <select id="coupon_status" name="coupon_status" @change="$el.form.submit()" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
            <option value="">Alle Status</option>
            <option value="open" @selected(($couponFilters['coupon_status'] ?? null) === 'open')>offen</option>
            <option value="redeemed" @selected(($couponFilters['coupon_status'] ?? null) === 'redeemed')>eingelöst</option>
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
    @if ($coupons->contains(fn ($c) => ! $c->isRedeemed() && $c->tenant_id === $tenant->id))
        <select id="coupon-assign-options-template" hidden aria-hidden="true">
            <option value="">Nutzer wählen…</option>
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
                        @if ($coupon->isRedeemed())
                            <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400">eingelöst</span>
                        @else
                            <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700">offen</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-slate-500 dark:text-slate-400">{{ $coupon->redeemedBy?->name ?? '—' }}</td>
                    <td class="px-4 py-2 text-slate-500 dark:text-slate-400 text-xs">{{ \App\Support\GermanTime::format($coupon->redeemed_at, 'd.m.Y H:i') ?? '—' }}</td>
                    <td class="px-4 py-2">
                        @if (! $coupon->isRedeemed() && $coupon->tenant_id === $tenant->id)
                            <form method="POST" action="{{ route('admin.coupons.assign', $coupon) }}" class="flex gap-1">
                                @csrf
                                <select name="user_id" required aria-label="Nutzer für Coupon {{ $coupon->code }} wählen" class="js-coupon-assign-select rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-xs">
                                    <option value="">Nutzer wählen…</option>
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
