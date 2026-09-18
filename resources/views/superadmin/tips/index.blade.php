<x-superadmin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Tipps & Tricks</h2>
            <div class="flex items-center gap-3">
                <a href="{{ route('superadmin.tips.categories.index') }}" class="px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 text-sm font-medium hover:bg-slate-50 dark:hover:bg-slate-700 transition">
                    Kategorien verwalten
                </a>
                <a href="{{ route('superadmin.tips.create') }}" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: #005FD7">
                    Neuer Tipp
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">
        @forelse ($categories as $category)
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
                <div class="px-4 py-3 bg-slate-50 dark:bg-slate-700 font-medium text-slate-700 dark:text-slate-200">
                    {{ $category->name }}
                </div>
                <table class="w-full text-sm">
                    <tbody>
                        @forelse ($category->tips as $tip)
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
                        @empty
                            <tr><td colspan="3" class="px-4 py-3 text-slate-400">Noch keine Tipps in dieser Kategorie.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @empty
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 text-slate-500">
                Noch keine Kategorien angelegt. <a href="{{ route('superadmin.tips.categories.index') }}" class="underline">Jetzt anlegen</a>.
            </div>
        @endforelse
    </div>
</x-superadmin-layout>
