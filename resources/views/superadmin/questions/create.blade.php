@php($revision = null)
<x-superadmin-layout>
    <x-slot name="header">
        <div>
            <div class="text-xs text-slate-400"><a href="{{ route('superadmin.questions.index', $module) }}" class="hover:underline">{{ $module->name }}</a></div>
            <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Neue Frage</h2>
        </div>
    </x-slot>

    <div class="max-w-2xl bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6">
        <form method="POST" action="{{ route('superadmin.questions.store', $module) }}" class="space-y-5">
            @csrf
            @include('superadmin.questions._form')
            <button type="submit" class="px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: #005FD7">
                Frage anlegen
            </button>
        </form>
    </div>
</x-superadmin-layout>
