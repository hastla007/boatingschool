<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Praxis-Anmeldung</h2>
    </x-slot>

    <a href="{{ route('admin.dashboard', ['tab' => 'praxis-anmeldungen']) }}" class="text-sm text-slate-500 hover:underline inline-block mb-4">&larr; Zurück zu Praxis-Anmeldungen</a>

    <div class="max-w-2xl bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 mb-6 flex items-center gap-4">
        <div class="w-12 h-12 rounded-full flex items-center justify-center text-white text-lg font-semibold shrink-0" style="background-color: var(--brand-primary, #005FD7)">
            {{-- Bug-Review B28: mb_substr/mb_strtoupper statt substr/strtoupper,
                 sonst zerstört ein mit einem Umlaut beginnender Name das
                 erste Zeichen. --}}
            {{ mb_strtoupper(mb_substr($submission->user->display_name ?? $submission->user->email, 0, 1)) }}
        </div>
        <div>
            <div class="text-lg font-semibold text-slate-800 dark:text-white">{{ $submission->user->display_name ?? $submission->user->email }}</div>
            <div class="text-sm text-slate-500">{{ $submission->user->email }}</div>
        </div>
        <a href="{{ route('admin.participants.show', $submission->user) }}" class="ms-auto text-xs text-slate-500 hover:underline whitespace-nowrap shrink-0">Teilnehmerprofil &rarr;</a>
    </div>

    <div class="max-w-2xl bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
        @include('admin.partials._praxis-submission', [
            'submission' => $submission,
            'submissionLocations' => $submissionLocations,
        ])
    </div>
</x-app-layout>
