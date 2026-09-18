<p class="text-slate-500 mb-6">
    Codes für deine Bootsschule sowie frei einlösbare Codes, die eure Nutzer eingelöst haben. Neue Codes werden
    vom Plattform-Betreiber erzeugt und euch zugeteilt.
</p>

<div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 dark:bg-slate-700 text-slate-500 dark:text-slate-300 text-left">
            <tr>
                <th class="px-4 py-2">Code</th>
                <th class="px-4 py-2">Typ</th>
                <th class="px-4 py-2">Kurs / Produkt</th>
                <th class="px-4 py-2">Status</th>
                <th class="px-4 py-2">Eingelöst von</th>
                <th class="px-4 py-2">Eingelöst am</th>
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
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-3 text-slate-500">Noch keine Codes für diese Bootsschule.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="p-4">{{ $coupons->links() }}</div>
</div>
