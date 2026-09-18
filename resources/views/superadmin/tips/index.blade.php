<x-superadmin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Tipps & Tricks</h2>
            <a href="{{ route('superadmin.tips.create') }}" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: #005FD7">
                Neuer Tipp
            </a>
        </div>
    </x-slot>

    <div class="space-y-6">
        @forelse ($tips as $category => $categoryTips)
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
                <div class="px-4 py-3 bg-slate-50 dark:bg-slate-700 font-medium text-slate-700 dark:text-slate-200">
                    {{ $category }}
                </div>
                <table class="w-full text-sm">
                    <tbody>
                        @foreach ($categoryTips as $tip)
                            <tr class="border-t border-slate-100 dark:border-slate-700 {{ $tip->active ? '' : 'opacity-50' }}">
                                <td class="px-4 py-3 font-medium text-slate-700 dark:text-slate-200">
                                    {{ $tip->title }}
                                    @if ($tip->pdfAsset)
                                        <span class="ml-2 inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-600 text-slate-500 dark:text-slate-300">PDF</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if ($tip->active)
                                        <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700">aktiv</span>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">deaktiviert</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <a href="{{ route('superadmin.tips.edit', $tip) }}" class="text-xs text-slate-500 hover:underline">Bearbeiten</a>
                                    <form method="POST" action="{{ route('superadmin.tips.toggle-active', $tip) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="ml-3 text-xs text-slate-500 hover:underline">{{ $tip->active ? 'Deaktivieren' : 'Aktivieren' }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('superadmin.tips.destroy', $tip) }}" class="inline" onsubmit="return confirm('Diesen Tipp wirklich löschen?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="ml-3 text-xs text-red-500 hover:underline">Löschen</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 text-slate-500">
                Noch keine Tipps & Tricks angelegt.
            </div>
        @endforelse
    </div>
</x-superadmin-layout>
