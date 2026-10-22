<x-superadmin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Addon-Vorlagen</h2>
            <a href="{{ route('superadmin.addon-templates.create') }}" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: #005FD7">
                Neue Vorlage
            </a>
        </div>
    </x-slot>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <tbody>
                @forelse ($templates as $template)
                    <tr class="border-t border-slate-100 dark:border-slate-700 first:border-t-0 {{ $template->active ? '' : 'opacity-50' }}">
                        <td class="px-4 py-3 font-medium text-slate-700 dark:text-slate-200">
                            {{ $template->title }}
                            <div class="text-xs text-slate-400">{{ $template->headline }}</div>
                        </td>
                        <td class="px-4 py-3">
                            @if ($template->active)
                                <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700">aktiv</span>
                            @else
                                <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">deaktiviert</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="{{ route('superadmin.addon-templates.edit', $template) }}" class="text-xs text-slate-500 hover:underline">Bearbeiten</a>
                            <form method="POST" action="{{ route('superadmin.addon-templates.toggle-active', $template) }}" class="inline">
                                @csrf
                                <button type="submit" class="ml-3 text-xs text-slate-500 hover:underline">{{ $template->active ? 'Deaktivieren' : 'Aktivieren' }}</button>
                            </form>
                            <form method="POST" action="{{ route('superadmin.addon-templates.destroy', $template) }}" class="inline" @submit="if (! confirm('Diese Addon-Vorlage wirklich löschen?')) $event.preventDefault()">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="ml-3 text-xs text-red-500 hover:underline">Löschen</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-3 text-slate-400">Noch keine Addon-Vorlagen angelegt.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-superadmin-layout>
