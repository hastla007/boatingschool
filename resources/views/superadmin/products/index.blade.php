<x-superadmin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Produkte</h2>
    </x-slot>

    <div class="grid lg:grid-cols-3 gap-4">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Produkt anlegen</h3>
            <form method="POST" action="{{ route('superadmin.products.store') }}" class="space-y-3">
                @csrf
                <div>
                    <x-input-label for="name" value="Name" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required placeholder="z. B. Fahrstunde 1 EH" />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="code" value="Code" />
                    <x-text-input id="code" name="code" type="text" class="mt-1 block w-full" :value="old('code')" required placeholder="z. B. FAHRSTUNDE-3-EH" />
                    <x-input-error :messages="$errors->get('code')" class="mt-2" />
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90 transition">Produkt anlegen</button>
            </form>
        </div>

        <div class="lg:col-span-2 bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-700 text-slate-500 dark:text-slate-300 text-left">
                    <tr>
                        <th class="px-4 py-2">Name</th>
                        <th class="px-4 py-2">Code</th>
                        <th class="px-4 py-2">Status</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($products as $product)
                        <tr class="border-t border-slate-100 dark:border-slate-700 {{ $product->active ? '' : 'opacity-50' }}">
                            <td class="px-4 py-2 font-medium text-slate-700 dark:text-slate-200">{{ $product->name }}</td>
                            <td class="px-4 py-2 text-slate-500 font-mono text-xs">{{ $product->code }}</td>
                            <td class="px-4 py-2">
                                @if ($product->active)
                                    <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700">aktiv</span>
                                @else
                                    <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">deaktiviert</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right">
                                <form method="POST" action="{{ route('superadmin.products.toggle-active', $product) }}">
                                    @csrf
                                    <button type="submit" class="text-xs text-slate-500 hover:underline">{{ $product->active ? 'Deaktivieren' : 'Aktivieren' }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-3 text-slate-500">Noch keine Produkte angelegt.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-superadmin-layout>
