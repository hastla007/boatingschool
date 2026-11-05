<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Inaktive Lernende</h2>
    </x-slot>

    <a href="{{ route('admin.dashboard', ['tab' => 'participants']) }}" class="text-sm text-slate-600 dark:text-slate-400 hover:underline">&larr; Zurück zu Teilnehmer</a>

    <div class="mt-4 space-y-4">
        <form method="GET" action="{{ route('admin.inactive.index') }}" x-data="{}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex flex-wrap items-end gap-3">
            <div>
                <label for="days" class="block text-xs text-slate-600 dark:text-slate-400 mb-1">Nicht gelernt seit</label>
                <select id="days" name="days" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm" @change="$el.form.requestSubmit()">
                    @foreach (\App\Http\Controllers\Admin\InactiveLearnerController::DAY_OPTIONS as $option)
                        <option value="{{ $option }}" @selected($days === $option)>{{ $option }} Tagen</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="px-4 py-2 rounded-lg bg-slate-100 dark:bg-slate-700 text-sm">Anzeigen</button>
            <p class="w-full text-xs text-slate-600 dark:text-slate-400">Lernende mit aktivem Kurszugang, die seit {{ $days }} Tagen keine Frage beantwortet haben. Pro Person geht höchstens alle {{ $cooldownDays }} Tage eine Erinnerung raus.</p>
        </form>

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-2 p-4 border-b border-slate-100 dark:border-slate-700">
                <span class="text-sm text-slate-700 dark:text-slate-200">{{ $rows->count() }} {{ $rows->count() === 1 ? 'Person' : 'Personen' }}</span>
                @php($remindable = $rows->reject(fn ($r) => $r['recently_reminded'])->count())
                @if ($remindable > 0)
                    <form method="POST" action="{{ route('admin.inactive.remind') }}" x-data="{}"
                          @submit="if (! confirm('{{ $remindable }} Erinnerung(en) per E-Mail verschicken?')) $event.preventDefault()">
                        @csrf
                        <input type="hidden" name="days" value="{{ $days }}">
                        <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background-color: var(--brand-primary, #005FD7)">Alle erinnern ({{ $remindable }})</button>
                    </form>
                @endif
            </div>
            <ul class="divide-y divide-slate-100 dark:divide-slate-700">
                @forelse ($rows as $row)
                    <li class="px-4 py-2 flex flex-wrap items-center justify-between gap-2 text-sm">
                        <a href="{{ route('admin.participants.show', $row['membership']->user_id) }}" class="hover:underline">
                            <span class="block text-slate-700 dark:text-slate-200">{{ $row['membership']->user->name }}</span>
                            <span class="block text-xs text-slate-600 dark:text-slate-400">{{ $row['membership']->user->email }}</span>
                        </a>
                        <span class="flex items-center gap-3">
                            <span class="text-xs text-slate-600 dark:text-slate-400">{{ $row['last_activity'] ? 'zuletzt '.$row['last_activity']->timezone('Europe/Berlin')->format('d.m.Y') : 'noch nie gelernt' }}</span>
                            @if ($row['recently_reminded'])
                                <span class="text-xs text-slate-600 dark:text-slate-400">erinnert am {{ $row['membership']->reminded_at->timezone('Europe/Berlin')->format('d.m.') }}</span>
                            @else
                                <form method="POST" action="{{ route('admin.inactive.remind') }}">
                                    @csrf
                                    <input type="hidden" name="days" value="{{ $days }}">
                                    <input type="hidden" name="user_id" value="{{ $row['membership']->user_id }}">
                                    <button type="submit" class="px-3 py-1 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 text-xs">Erinnern</button>
                                </form>
                            @endif
                        </span>
                    </li>
                @empty
                    <li class="px-4 py-6 text-sm text-slate-600 dark:text-slate-400">Alle Lernenden mit Kurszugang waren in den letzten {{ $days }} Tagen aktiv.</li>
                @endforelse
            </ul>
        </div>
    </div>
</x-app-layout>
