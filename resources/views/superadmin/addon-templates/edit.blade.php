<x-superadmin-layout>
    <x-slot name="header">
        <div>
            <div class="text-xs text-slate-600 dark:text-slate-400"><a href="{{ route('superadmin.addon-templates.index') }}" class="hover:underline">Addon-Vorlagen</a></div>
            <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Addon-Vorlage bearbeiten</h2>
        </div>
    </x-slot>

    <div class="max-w-2xl bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6">
        <form method="POST" action="{{ route('superadmin.addon-templates.update', $template) }}" class="space-y-5">
            @csrf
            @method('PATCH')
            @include('superadmin.addon-templates._form')
            <button type="submit" class="px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: #005FD7">
                Änderungen speichern
            </button>
        </form>
    </div>
</x-superadmin-layout>
