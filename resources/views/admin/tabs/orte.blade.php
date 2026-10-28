{{-- Bug-Review (Du/Sie im Admin-Bereich gemischt): der restliche
     Admin-Bereich (siehe tabs/dashboard.blade.php, tabs/branding.blade.php)
     siezt durchgehend -- hier stand bisher als einzige Stelle die
     Du-Form. --}}
<p class="text-slate-500 mb-6">
    Hier können Sie einen oder mehrere Orte für Praxis &amp; Prüfung anlegen. Ihr Kunde wählt im Praxisformular
    immer den Praxisort &mdash; der zugehörige Prüfungsort ist fix daran gekoppelt (kann mit dem Praxisort
    identisch sein, z. B. Kurs Binnen: Praxis an Ort X, Prüfung an Ort Y). Sie können mehrere Orte für denselben
    Kurs anlegen (z. B. bei mehreren Standorten) und einen Ort mehreren Kursen gleichzeitig zuweisen.
</p>

<div class="space-y-6" x-data="{ showCreateForm: {{ $errors->has('praxis_ort') && old('_form') === 'create' ? 'true' : 'false' }} }">
    @foreach ($tenantCourseLocations as $location)
        <div class="max-w-2xl bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 space-y-4">
            <div class="font-medium text-slate-700 dark:text-slate-200">
                {{ $location->praxis_ort }}
                @if ($location->pruefung_ort !== $location->praxis_ort)
                    <span class="text-slate-600 dark:text-slate-400 font-normal">&middot; Prüfung: {{ $location->pruefung_ort }}</span>
                @endif
                @if ($location->hasTheorieOrt())
                    <span class="text-slate-600 dark:text-slate-400 font-normal">&middot; Theorie: {{ $location->theorie_ort }}</span>
                @endif
                @if ($location->pruefungsausschuss)
                    <span class="text-slate-600 dark:text-slate-400 font-normal">&middot; Prüfungsausschuss: {{ $location->pruefungsausschuss->label() }}</span>
                @endif
            </div>

            {{-- Sicherheitsreview H3: Anlegen/Ändern/Löschen bleibt serverseitig
                 owner/admin vorbehalten (siehe routes/web.php) -- das hier
                 vermeidet nur ein unbedienbares Formular für einen Instructor. --}}
            @if ($canManageTenant)
                <form method="POST" action="{{ route('admin.tenant-course-locations.update', $location) }}" class="space-y-4">
                    @csrf
                    @method('PATCH')

                    @include('admin.tabs._tenant-course-location-fields', ['location' => $location, 'prefix' => 'location-'.$location->id])

                    <button type="submit" class="px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                        Änderungen speichern
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.tenant-course-locations.destroy', $location) }}" @submit="if (! confirm('Diesen Ort wirklich löschen?')) $event.preventDefault()">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-xs text-red-500 hover:underline">Ort löschen</button>
                </form>
            @endif
        </div>
    @endforeach

    @if ($canManageTenant)
        <div class="max-w-2xl">
            <button type="button" @click="showCreateForm = ! showCreateForm" x-show="! showCreateForm"
                    class="px-5 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 text-sm font-medium hover:bg-slate-50 dark:hover:bg-slate-700 transition">
                + Neuen Ort anlegen
            </button>

            <div x-show="showCreateForm" style="display: none" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 space-y-4">
                <form method="POST" action="{{ route('admin.tenant-course-locations.store') }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="_form" value="create">

                    @include('admin.tabs._tenant-course-location-fields', ['location' => null, 'prefix' => 'new'])

                    <div class="flex items-center gap-3 pt-2">
                        <button type="submit" class="px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                            Ort anlegen
                        </button>
                        <button type="button" @click="showCreateForm = false" class="text-sm text-slate-500 hover:underline">
                            Abbrechen
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
