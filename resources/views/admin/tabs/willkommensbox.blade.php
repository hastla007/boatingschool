{{--
    Bug-Review F6: Willkommensbox-Bestellungen ließen sich bisher nur
    einzeln auf der Teilnehmer-Detailseite finden -- diese Übersicht zeigt
    alle Bestellungen der Bootsschule an einem Ort, offene zuerst.
--}}
{{-- Bug-Review (Du/Sie im Admin-Bereich gemischt): der restliche
     Admin-Bereich siezt durchgehend. --}}
<p class="text-slate-500 dark:text-slate-400 mb-6">
    Bestellungen der Willkommensbox Ihrer Lerner. Offene Bestellungen stehen oben.
</p>

<div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
    <form method="GET" action="{{ route('admin.dashboard') }}" class="flex flex-wrap gap-2 p-4 border-b border-slate-100 dark:border-slate-700">
        <input type="hidden" name="tab" value="willkommensbox">
        <label for="wb_search" class="sr-only">Name oder E-Mail suchen</label>
        <input type="text" id="wb_search" name="wb_search" value="{{ $willkommensboxFilters['wb_search'] ?? '' }}"
               placeholder="Name oder E-Mail suchen…"
               class="flex-1 min-w-[220px] rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
        <button type="submit" class="px-4 py-2 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 transition text-sm">Suchen</button>
        @if (($willkommensboxFilters['wb_search'] ?? '') !== '')
            <a href="{{ route('admin.dashboard', ['tab' => 'willkommensbox']) }}" class="text-sm text-slate-500 dark:text-slate-400 hover:underline self-center">Filter zurücksetzen</a>
        @endif
    </form>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 dark:bg-slate-700 text-slate-500 dark:text-slate-300 text-left">
            <tr>
                <th class="px-4 py-2">Teilnehmer</th>
                <th class="px-4 py-2">Lieferadresse</th>
                <th class="px-4 py-2">Bestellt am</th>
                <th class="px-4 py-2">Status</th>
                <th class="px-4 py-2"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($willkommensboxOrders as $order)
                <tr class="border-t border-slate-100 dark:border-slate-700">
                    <td class="px-4 py-2">
                        <a href="{{ route('admin.participants.show', $order->user) }}" class="flex items-center gap-2 hover:underline">
                            <div class="w-7 h-7 rounded-full flex items-center justify-center text-white text-xs font-semibold shrink-0" style="background-color: var(--brand-primary, #005FD7)">
                                {{ mb_strtoupper(mb_substr($order->user->display_name ?? $order->user->email, 0, 1)) }}
                            </div>
                            <span class="text-slate-700 dark:text-slate-200">{{ $order->user->display_name ?? $order->user->email }}</span>
                        </a>
                    </td>
                    <td class="px-4 py-2 text-slate-600 dark:text-slate-300">{{ $order->street }}, {{ $order->postal_code }} {{ $order->city }}</td>
                    <td class="px-4 py-2 text-slate-500 dark:text-slate-400 text-xs whitespace-nowrap">{{ \App\Support\GermanTime::format($order->created_at, 'd.m.Y H:i') }}</td>
                    <td class="px-4 py-2">
                        @if ($order->isShipped())
                            <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">
                                Versendet {{ $order->shipped_at->format('d.m.Y') }}
                                @if ($order->tracking_number)
                                    &middot; {{ $order->tracking_number }}
                                @endif
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">Offen</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-right whitespace-nowrap">
                        @if (! $order->isShipped())
                            <form method="POST" action="{{ route('admin.willkommensbox-orders.ship', $order) }}" class="inline-flex items-center gap-1.5">
                                @csrf
                                @method('PATCH')
                                <label for="tracking_number_{{ $order->id }}" class="sr-only">Tracking-Nummer für {{ $order->user->display_name ?? $order->user->email }}</label>
                                <input type="text" id="tracking_number_{{ $order->id }}" name="tracking_number" placeholder="Tracking (optional)" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-xs w-32">
                                <button type="submit" class="px-2.5 py-1 rounded-lg text-white text-xs font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                                    Versendet
                                </button>
                            </form>
                        @endif
                        @if (! empty($willkommensboxLabelSizes))
                            @foreach ($willkommensboxLabelSizes as $size)
                                <a href="{{ route('admin.willkommensbox-orders.label', [$order, $size]) }}" class="ms-1.5 text-xs hover:underline" style="color: var(--brand-primary, #005FD7)">{{ strtoupper($size) }}</a>
                            @endforeach
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-3 text-slate-500 dark:text-slate-400">
                    @if (array_filter($willkommensboxFilters))
                        Keine Bestellungen gefunden, die zu diesem Filter passen.
                    @else
                        Noch keine Willkommensbox-Bestellungen eingegangen.
                    @endif
                </td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
    <div class="p-4">{{ $willkommensboxOrders->links() }}</div>
</div>
