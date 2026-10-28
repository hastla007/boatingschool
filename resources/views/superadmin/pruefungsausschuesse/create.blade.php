<x-superadmin-layout>
    <x-slot name="header">
        <div>
            <div class="text-xs text-slate-600 dark:text-slate-400"><a href="{{ route('superadmin.pruefungsausschuesse.index') }}" class="hover:underline">Prüfungsausschüsse</a></div>
            <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Neuer Prüfungsausschuss</h1>
        </div>
    </x-slot>

    <div class="max-w-2xl bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6">
        <form method="POST" action="{{ route('superadmin.pruefungsausschuesse.store') }}" class="space-y-5">
            @csrf
            @include('superadmin.pruefungsausschuesse._form')
            <button type="submit" class="px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: #005FD7">
                Prüfungsausschuss anlegen
            </button>
        </form>
    </div>
</x-superadmin-layout>
