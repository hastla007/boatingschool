<x-superadmin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Frage bearbeiten</h2>
    </x-slot>

    <div class="max-w-2xl space-y-4">
        <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-900/40 rounded-xl px-4 py-3 text-sm text-amber-800 dark:text-amber-200">
            Speichern erzeugt eine neue Fassung dieser Frage (Revision {{ ($revision?->revision_no ?? 0) + 1 }}); die bisherige Fassung bleibt unveränderlich in der Historie erhalten.
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6">
            <form method="POST" action="{{ route('superadmin.questions.update', $question) }}" class="space-y-5">
                @csrf
                @method('PATCH')
                @include('superadmin.questions._form')
                <button type="submit" class="px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: #005FD7">
                    Neue Fassung speichern
                </button>
            </form>
        </div>
    </div>
</x-superadmin-layout>
