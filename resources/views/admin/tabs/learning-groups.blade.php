{{-- Lerngruppen (Gruppenlizenzen der App), nur Inhaber/Admins. Hier nur die
     Liste (eine Abfrage, AdminDashboardController); Anlegen, Bearbeiten,
     Mitglieder und Beenden auf eigenen Seiten (Admin\LearningGroupController),
     damit das Dashboard nicht alle Teilnehmer als Verwalterauswahl lädt. --}}
<div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
    <p class="text-slate-500 dark:text-slate-400">
        Eine Lerngruppe ist eine Gruppenlizenz mit fester Platzzahl für einen oder mehrere Kurse. Der Verwalter
        &ndash; ein aktiver Teilnehmer Ihrer Bootsschule &ndash; lädt in der App ein; wer beitritt, erhält die
        gewählten Kurse bzw. Funktionen bis zum Ende der Gruppe.
    </p>
    <a href="{{ route('admin.learning-groups.create') }}" class="shrink-0 px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">+ Neue Lerngruppe</a>
</div>

<div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden mb-6">
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 dark:bg-slate-700 text-slate-500 dark:text-slate-300 text-left">
            <tr>
                <th class="px-4 py-2">Name</th>
                <th class="px-4 py-2">Verwalter</th>
                <th class="px-4 py-2">Plätze</th>
                <th class="px-4 py-2">Kurse</th>
                <th class="px-4 py-2">Gültig bis</th>
                <th class="px-4 py-2">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($learningGroups as $group)
                @php($statusLabel = \App\Http\Controllers\Admin\LearningGroupController::statusLabel($group))
                <tr class="border-t border-slate-100 dark:border-slate-700">
                    <td class="px-4 py-2">
                        <a href="{{ route('admin.learning-groups.show', $group) }}" class="font-medium hover:underline" style="color: var(--brand-primary, #005FD7)">{{ $group->name }}</a>
                    </td>
                    <td class="px-4 py-2 text-slate-700 dark:text-slate-200">{{ $group->owner?->name ?? '— (kein Verwalter)' }}</td>
                    <td class="px-4 py-2 text-slate-700 dark:text-slate-200 whitespace-nowrap">{{ $group->used_seats }} / {{ $group->seats }}</td>
                    <td class="px-4 py-2 text-slate-700 dark:text-slate-200">
                        @foreach ($group->scope_summary as $scope)
                            <div>{{ $scope['course_name'] }} <span class="text-xs text-slate-500 dark:text-slate-400">({{ $scope['feature_label'] }})</span></div>
                        @endforeach
                    </td>
                    <td class="px-4 py-2 text-slate-700 dark:text-slate-200 whitespace-nowrap">{{ $group->valid_until?->timezone('Europe/Berlin')->format('d.m.Y') ?? 'unbefristet' }}</td>
                    <td class="px-4 py-2 whitespace-nowrap">
                        <span class="text-xs px-2 py-0.5 rounded-full {{ $statusLabel === 'Aktiv' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300' }}">{{ $statusLabel }}</span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-3 text-slate-500 dark:text-slate-400">Noch keine Lerngruppen angelegt.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>

