<x-superadmin-layout>
    <x-slot name="header">
        <div>
            <div class="text-xs text-slate-400"><a href="{{ route('superadmin.tips.index') }}" class="hover:underline">Tipps & Tricks</a></div>
            <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Kategorien</h2>
        </div>
    </x-slot>

    <div class="grid lg:grid-cols-3 gap-4">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Kategorie anlegen</h3>
            <form method="POST" action="{{ route('superadmin.tips.categories.store') }}" class="space-y-3">
                @csrf
                <div>
                    <x-input-label for="name" value="Name" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required placeholder="z. B. Knoten" />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="sort_order" value="Reihenfolge" />
                    <x-text-input id="sort_order" name="sort_order" type="number" class="mt-1 block w-full" :value="old('sort_order', 0)" />
                    <x-input-error :messages="$errors->get('sort_order')" class="mt-2" />
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90 transition">Kategorie anlegen</button>
            </form>
        </div>

        <div class="lg:col-span-2 bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
            @if ($errors->has('category'))
                <div class="px-4 py-3 bg-red-50 text-red-700 text-sm border-b border-red-100">{{ $errors->first('category') }}</div>
            @endif
            <table class="w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-700 text-slate-500 dark:text-slate-300 text-left">
                    <tr>
                        <th class="px-4 py-2">Name</th>
                        <th class="px-4 py-2">Reihenfolge</th>
                        <th class="px-4 py-2">Tipps</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($categories as $category)
                        @php($formId = 'category-form-'.$category->id)
                        <tr class="border-t border-slate-100 dark:border-slate-700">
                            <td class="px-4 py-2">
                                <input form="{{ $formId }}" type="text" name="name" value="{{ $category->name }}" class="block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm" required>
                            </td>
                            <td class="px-4 py-2">
                                <input form="{{ $formId }}" type="number" name="sort_order" value="{{ $category->sort_order }}" class="block w-20 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                            </td>
                            <td class="px-4 py-2 text-slate-500">{{ $category->tips_count }}</td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                <form id="{{ $formId }}" method="POST" action="{{ route('superadmin.tips.categories.update', $category) }}" class="inline">
                                    @csrf
                                    @method('PATCH')
                                </form>
                                <button type="submit" form="{{ $formId }}" class="text-xs text-slate-500 hover:underline">Speichern</button>
                                <form method="POST" action="{{ route('superadmin.tips.categories.destroy', $category) }}" class="inline" onsubmit="return confirm('Diese Kategorie wirklich löschen?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="ml-3 text-xs text-red-500 hover:underline" @disabled($category->tips_count > 0)>Löschen</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-3 text-slate-500">Noch keine Kategorien angelegt.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-superadmin-layout>
