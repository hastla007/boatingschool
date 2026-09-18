<x-superadmin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Module</h2>
            <a href="{{ route('superadmin.courses.index') }}" class="px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 text-sm font-medium hover:bg-slate-50 dark:hover:bg-slate-700 transition">Zu den Kursen</a>
        </div>
    </x-slot>

    <div class="grid lg:grid-cols-3 gap-4">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Neues Modul</h3>
            <form method="POST" action="{{ route('superadmin.modules.store') }}" class="space-y-3">
                @csrf
                <div>
                    <x-input-label for="code" value="Code" />
                    <x-text-input id="code" name="code" type="text" class="mt-1 block w-full" :value="old('code')" required />
                    <x-input-error :messages="$errors->get('code')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="name" value="Name" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="version" value="Version (optional)" />
                    <x-text-input id="version" name="version" type="text" class="mt-1 block w-full" :value="old('version')" />
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90 transition">Modul anlegen</button>
            </form>
        </div>

        <div class="lg:col-span-2 bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-700 text-slate-500 dark:text-slate-300 text-left">
                    <tr>
                        <th class="px-4 py-2">Name</th>
                        <th class="px-4 py-2">Code</th>
                        <th class="px-4 py-2">Fragen</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($modules as $module)
                        <tr class="border-t border-slate-100 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700/50">
                            <td class="px-4 py-2">
                                <a href="{{ route('superadmin.questions.index', $module) }}" class="font-medium text-slate-700 dark:text-slate-200 hover:underline">{{ $module->name }}</a>
                            </td>
                            <td class="px-4 py-2 text-slate-500 font-mono text-xs">{{ $module->code }}</td>
                            <td class="px-4 py-2 text-slate-500">{{ $module->questions_count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-superadmin-layout>
