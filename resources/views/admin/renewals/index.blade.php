<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Kurszugänge verlängern</h2>
    </x-slot>

    <a href="{{ route('admin.dashboard', ['tab' => 'participants']) }}" class="text-sm text-slate-600 dark:text-slate-400 hover:underline">&larr; Zurück zu Teilnehmer</a>

    <div class="mt-4 space-y-4">
        @if ($courses->isNotEmpty())
        <form method="GET" action="{{ route('admin.renewals.index') }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex flex-wrap items-end gap-3">
            <div>
                <label for="course_id" class="block text-xs text-slate-600 dark:text-slate-400 mb-1">Kurs</label>
                <select id="course_id" name="course_id" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                    @foreach ($courses as $course)
                        <option value="{{ $course->id }}" @selected($courseId === $course->id)>{{ $course->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="window" class="block text-xs text-slate-600 dark:text-slate-400 mb-1">Zugang endet in den nächsten</label>
                <select id="window" name="window" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                    @foreach (\App\Http\Controllers\Admin\BulkRenewalController::WINDOWS as $option)
                        <option value="{{ $option }}" @selected($window === $option)>{{ $option }} Tagen</option>
                    @endforeach
                </select>
            </div>
            <input type="hidden" name="include_expired" value="0">
            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200 pb-2">
                <input type="checkbox" name="include_expired" value="1" @checked($includeExpired) class="rounded border-slate-300">
                auch in den letzten 90 Tagen abgelaufene
            </label>
            <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90">Anzeigen</button>
        </form>
        @endif

        @if ($courseId)
            <form method="POST" action="{{ route('admin.renewals.store') }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm" x-data="{ all: true }">
                @csrf
                <input type="hidden" name="course_id" value="{{ $courseId }}">
                <div class="p-4 border-b border-slate-100 dark:border-slate-700 flex flex-wrap items-center justify-between gap-3 text-sm">
                    <span class="text-slate-700 dark:text-slate-200">
                        {{ $entitlements->count() }} {{ $entitlements->count() === 1 ? 'Zugang' : 'Zugänge' }} ·
                        <strong>{{ $freeCodes }}</strong> freie Verlängerungscodes · Verlängerung um {{ $validityDays }} Tage je Code
                    </span>
                    @if ($entitlements->isNotEmpty())
                        <button type="submit" class="px-4 py-2 rounded-lg text-white font-medium hover:opacity-90 disabled:opacity-50" style="background-color: var(--brand-primary, #005FD7)" @disabled($freeCodes === 0)
                                @click="if (! confirm('Ausgewählte Zugänge verlängern? Je Zugang wird ein Verlängerungscode verbraucht.')) $event.preventDefault()">Ausgewählte verlängern</button>
                    @endif
                </div>
                @if ($freeCodes < $entitlements->count() && $entitlements->isNotEmpty())
                    <p class="px-4 pt-3 text-sm text-amber-800 dark:text-amber-200">Es reichen nicht alle Codes: verlängert werden die zuerst endenden Zugänge, bis die Codes aufgebraucht sind.</p>
                @endif
                <x-input-error :messages="$errors->get('entitlement_ids')" class="px-4 pt-3" />
                <ul class="divide-y divide-slate-100 dark:divide-slate-700">
                    @forelse ($entitlements as $entitlement)
                        <li class="px-4 py-2 flex flex-wrap items-center gap-3 text-sm">
                            <input type="checkbox" name="entitlement_ids[]" value="{{ $entitlement->id }}" checked class="rounded border-slate-300" aria-label="{{ $entitlement->user->display_name ?? $entitlement->user->email }} verlängern">
                            <input type="hidden" name="seen[{{ $entitlement->id }}]" value="{{ $entitlement->valid_until->timestamp }}">
                            <span class="flex-1 min-w-0">
                                <span class="block text-slate-700 dark:text-slate-200">{{ $entitlement->user->display_name ?? $entitlement->user->email }}</span>
                                <span class="block text-xs text-slate-600 dark:text-slate-400">{{ $entitlement->user->email }}</span>
                            </span>
                            <span class="text-xs {{ $entitlement->valid_until->isPast() ? 'text-red-700 dark:text-red-300' : 'text-slate-600 dark:text-slate-400' }}">
                                {{ $entitlement->valid_until->isPast() ? 'abgelaufen am' : 'endet am' }} {{ $entitlement->valid_until->timezone('Europe/Berlin')->format('d.m.Y') }}
                                → neu {{ ($entitlement->valid_until->isFuture() ? $entitlement->valid_until : now())->copy()->addDays($validityDays)->timezone('Europe/Berlin')->format('d.m.Y') }}
                            </span>
                        </li>
                    @empty
                        <li class="px-4 py-6 text-sm text-slate-600 dark:text-slate-400">Keine Zugänge in diesem Zeitraum.</li>
                    @endforelse
                </ul>
            </form>
        @else
            <p class="text-sm text-slate-600 dark:text-slate-400">Noch keine befristeten Kurszugänge.</p>
        @endif
    </div>
</x-app-layout>
