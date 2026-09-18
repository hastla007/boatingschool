<x-superadmin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-xs text-slate-400"><a href="{{ route('superadmin.modules.index') }}" class="hover:underline">Module</a></div>
                <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">{{ $module->name }} &middot; Fragen</h2>
            </div>
            <a href="{{ route('superadmin.questions.create', $module) }}" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90 transition">+ Neue Frage</a>
        </div>
    </x-slot>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 dark:bg-slate-700 text-slate-500 dark:text-slate-300 text-left">
                <tr>
                    <th class="px-4 py-2">Frage</th>
                    <th class="px-4 py-2">Thema</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($questions as $question)
                    @php($revision = $question->revisions->firstWhere('editorial_status', 'published') ?? $question->revisions->first())
                    <tr class="border-t border-slate-100 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700/50 {{ $question->active ? '' : 'opacity-50' }}">
                        <td class="px-4 py-2 max-w-md">
                            <a href="{{ route('superadmin.questions.edit', $question) }}" class="text-slate-700 dark:text-slate-200 hover:underline line-clamp-2">{{ $revision?->question_text ?? '(keine Fassung)' }}</a>
                        </td>
                        <td class="px-4 py-2 text-slate-500">{{ $revision?->smartmodus_kategorie ?: $revision?->topic }}</td>
                        <td class="px-4 py-2 text-slate-500">{{ $question->active ? 'aktiv' : 'deaktiviert' }}</td>
                        <td class="px-4 py-2 text-right">
                            <form method="POST" action="{{ route('superadmin.questions.toggle-active', $question) }}">
                                @csrf
                                <button type="submit" class="text-xs text-slate-500 hover:underline">{{ $question->active ? 'Deaktivieren' : 'Aktivieren' }}</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-superadmin-layout>
