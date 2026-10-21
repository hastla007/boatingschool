<x-superadmin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Prüfungsausschüsse</h2>
                <p class="text-xs text-slate-400 mt-0.5">Feste, plattformweite Adressen -- Bootsschulen können sie im Orte-Tab einem Praxisort zuordnen.</p>
            </div>
            <a href="{{ route('superadmin.pruefungsausschuesse.create') }}" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: #005FD7">
                Neuer Prüfungsausschuss
            </a>
        </div>
    </x-slot>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <tbody>
                @forelse ($pruefungsausschuesse as $pa)
                    <tr class="border-t border-slate-100 dark:border-slate-700 first:border-t-0">
                        <td class="px-4 py-3 font-medium text-slate-700 dark:text-slate-200">
                            {{ $pa->kommission }}
                            @if ($pa->standorttyp)
                                <span class="text-slate-400 font-normal">&middot; {{ $pa->standorttyp }}</span>
                            @endif
                            <div class="text-xs text-slate-400">{{ $pa->adresse() ?? '—' }}</div>
                        </td>
                        <td class="px-4 py-3 text-slate-500 dark:text-slate-400 text-xs">
                            {{ $pa->kontaktperson }}
                            @if ($pa->telefon)
                                <div>{{ $pa->telefon }}</div>
                            @endif
                            @if ($pa->email)
                                <div>{{ $pa->email }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="{{ route('superadmin.pruefungsausschuesse.edit', $pa) }}" class="text-xs text-slate-500 hover:underline">Bearbeiten</a>
                            <form method="POST" action="{{ route('superadmin.pruefungsausschuesse.destroy', $pa) }}" class="inline" onsubmit="return confirm('Diesen Prüfungsausschuss wirklich löschen? Praxisorte, die ihn zugeordnet haben, verlieren die Zuordnung.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="ml-3 text-xs text-red-500 hover:underline">Löschen</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-3 text-slate-400">Noch keine Prüfungsausschüsse angelegt.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-superadmin-layout>
