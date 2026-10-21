@php($location = $location ?? null)
@php($sameByDefault = ! $location || (
    $location->praxis_ort === $location->pruefung_ort
    && $location->praxis_strasse === $location->pruefung_strasse
    && $location->praxis_plz === $location->pruefung_plz
    && $location->praxis_stadt === $location->pruefung_stadt
    && $location->praxis_land === $location->pruefung_land
    && $location->praxis_lat === $location->pruefung_lat
    && $location->praxis_lng === $location->pruefung_lng
))
@php($hasTheorieOrt = $location && $location->hasTheorieOrt())
@php($initialPruefungsausschussId = old('pruefungsausschuss_id', $location?->pruefungsausschuss_id))
@php($pruefungsausschussInfo = $pruefungsausschuesse->mapWithKeys(fn ($pa) => [
    $pa->id => [
        'adresse' => $pa->adresse(),
        'kurse' => collect($pa->course_ids ?? [])->map(fn ($id) => $courses->firstWhere('id', $id)?->name)->filter()->values(),
    ],
]))

<div x-data="{
        sameAsLocation: {{ $sameByDefault ? 'true' : 'false' }},
        theorieOrtEnabled: {{ $hasTheorieOrt ? 'true' : 'false' }},
        pruefungsausschussId: {{ \Illuminate\Support\Js::from((string) $initialPruefungsausschussId) }},
        pruefungsausschussInfo: {{ \Illuminate\Support\Js::from($pruefungsausschussInfo) }},
     }" class="space-y-4">
    <div class="grid sm:grid-cols-2 gap-4">
        <div class="space-y-3">
            <div>
                <x-input-label :for="$prefix.'-praxis_ort'" value="Praxisort" />
                <x-text-input :id="$prefix.'-praxis_ort'" name="praxis_ort" type="text" placeholder="z. B. Hauptstandort"
                              class="mt-1 block w-full" :value="old('praxis_ort', $location?->praxis_ort)" required />
                <x-input-error :messages="$errors->get('praxis_ort')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$prefix.'-praxis_strasse'" value="Straße & Nr." />
                <x-text-input :id="$prefix.'-praxis_strasse'" name="praxis_strasse" type="text" placeholder="z. B. Hafenstraße 12"
                              class="mt-1 block w-full" :value="old('praxis_strasse', $location?->praxis_strasse)" />
                <x-input-error :messages="$errors->get('praxis_strasse')" class="mt-2" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <x-input-label :for="$prefix.'-praxis_plz'" value="PLZ" />
                    <x-text-input :id="$prefix.'-praxis_plz'" name="praxis_plz" type="text" placeholder="20095"
                                  class="mt-1 block w-full" :value="old('praxis_plz', $location?->praxis_plz)" />
                    <x-input-error :messages="$errors->get('praxis_plz')" class="mt-2" />
                </div>
                <div>
                    <x-input-label :for="$prefix.'-praxis_stadt'" value="Stadt" />
                    <x-text-input :id="$prefix.'-praxis_stadt'" name="praxis_stadt" type="text" placeholder="Hamburg"
                                  class="mt-1 block w-full" :value="old('praxis_stadt', $location?->praxis_stadt)" />
                    <x-input-error :messages="$errors->get('praxis_stadt')" class="mt-2" />
                </div>
            </div>
            <div>
                <x-input-label :for="$prefix.'-praxis_land'" value="Land" />
                <x-text-input :id="$prefix.'-praxis_land'" name="praxis_land" type="text" placeholder="Deutschland"
                              class="mt-1 block w-full" :value="old('praxis_land', $location?->praxis_land)" />
                <x-input-error :messages="$errors->get('praxis_land')" class="mt-2" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <x-input-label :for="$prefix.'-praxis_lat'" value="Breitengrad" />
                    <x-text-input :id="$prefix.'-praxis_lat'" name="praxis_lat" type="text" inputmode="decimal" placeholder="53.5460740"
                                  class="mt-1 block w-full" :value="old('praxis_lat', $location?->praxis_lat)" />
                    <x-input-error :messages="$errors->get('praxis_lat')" class="mt-2" />
                </div>
                <div>
                    <x-input-label :for="$prefix.'-praxis_lng'" value="Längengrad" />
                    <x-text-input :id="$prefix.'-praxis_lng'" name="praxis_lng" type="text" inputmode="decimal" placeholder="9.9826940"
                                  class="mt-1 block w-full" :value="old('praxis_lng', $location?->praxis_lng)" />
                    <x-input-error :messages="$errors->get('praxis_lng')" class="mt-2" />
                </div>
            </div>
            <p class="text-xs text-slate-400">
                Für die Karte im Praxisformular: Ort bei Google Maps suchen, Rechtsklick auf den Punkt &rarr; Koordinaten kopieren.
            </p>
        </div>
        <div class="space-y-3">
            <div>
                <x-input-label :for="$prefix.'-pruefung_ort'" value="Prüfungsort" />
                <x-text-input :id="$prefix.'-pruefung_ort'" name="pruefung_ort" type="text" placeholder="z. B. Hauptstandort"
                              class="mt-1 block w-full" x-bind:disabled="sameAsLocation" :value="old('pruefung_ort', $location?->pruefung_ort)" />
                <label class="inline-flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400 mt-1.5">
                    <input type="checkbox" x-model="sameAsLocation"
                           class="rounded border-slate-300 dark:bg-slate-700 dark:border-slate-600">
                    Gleich wie Praxisort
                </label>
                <x-input-error :messages="$errors->get('pruefung_ort')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$prefix.'-pruefung_strasse'" value="Straße & Nr." />
                <x-text-input :id="$prefix.'-pruefung_strasse'" name="pruefung_strasse" type="text" placeholder="z. B. Hafenstraße 12"
                              class="mt-1 block w-full" x-bind:disabled="sameAsLocation" :value="old('pruefung_strasse', $location?->pruefung_strasse)" />
                <x-input-error :messages="$errors->get('pruefung_strasse')" class="mt-2" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <x-input-label :for="$prefix.'-pruefung_plz'" value="PLZ" />
                    <x-text-input :id="$prefix.'-pruefung_plz'" name="pruefung_plz" type="text" placeholder="20095"
                                  class="mt-1 block w-full" x-bind:disabled="sameAsLocation" :value="old('pruefung_plz', $location?->pruefung_plz)" />
                    <x-input-error :messages="$errors->get('pruefung_plz')" class="mt-2" />
                </div>
                <div>
                    <x-input-label :for="$prefix.'-pruefung_stadt'" value="Stadt" />
                    <x-text-input :id="$prefix.'-pruefung_stadt'" name="pruefung_stadt" type="text" placeholder="Hamburg"
                                  class="mt-1 block w-full" x-bind:disabled="sameAsLocation" :value="old('pruefung_stadt', $location?->pruefung_stadt)" />
                    <x-input-error :messages="$errors->get('pruefung_stadt')" class="mt-2" />
                </div>
            </div>
            <div>
                <x-input-label :for="$prefix.'-pruefung_land'" value="Land" />
                <x-text-input :id="$prefix.'-pruefung_land'" name="pruefung_land" type="text" placeholder="Deutschland"
                              class="mt-1 block w-full" x-bind:disabled="sameAsLocation" :value="old('pruefung_land', $location?->pruefung_land)" />
                <x-input-error :messages="$errors->get('pruefung_land')" class="mt-2" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <x-input-label :for="$prefix.'-pruefung_lat'" value="Breitengrad" />
                    <x-text-input :id="$prefix.'-pruefung_lat'" name="pruefung_lat" type="text" inputmode="decimal" placeholder="53.5460740"
                                  class="mt-1 block w-full" x-bind:disabled="sameAsLocation" :value="old('pruefung_lat', $location?->pruefung_lat)" />
                    <x-input-error :messages="$errors->get('pruefung_lat')" class="mt-2" />
                </div>
                <div>
                    <x-input-label :for="$prefix.'-pruefung_lng'" value="Längengrad" />
                    <x-text-input :id="$prefix.'-pruefung_lng'" name="pruefung_lng" type="text" inputmode="decimal" placeholder="9.9826940"
                                  class="mt-1 block w-full" x-bind:disabled="sameAsLocation" :value="old('pruefung_lng', $location?->pruefung_lng)" />
                    <x-input-error :messages="$errors->get('pruefung_lng')" class="mt-2" />
                </div>
            </div>
        </div>
    </div>

    <div class="border-t border-slate-100 dark:border-slate-700 pt-4">
        <button type="button" @click="theorieOrtEnabled = ! theorieOrtEnabled" x-show="! theorieOrtEnabled"
                class="text-sm font-medium hover:underline" style="color: var(--brand-primary, #005FD7)">
            + Theorieort hinzufügen
        </button>
        <p x-show="! theorieOrtEnabled" class="text-xs text-slate-400 mt-1">
            Optional &mdash; z. B. wenn der Theorieteil eines Navigationskurses an einem anderen Ort als die Praxis stattfindet.
        </p>

        <div x-show="theorieOrtEnabled" style="display: none" class="space-y-3">
            <div class="flex items-center justify-between">
                <x-input-label value="Theorieort (optional)" />
                <button type="button" @click="theorieOrtEnabled = false" class="text-xs text-slate-400 hover:underline">
                    Entfernen
                </button>
            </div>
            <div>
                <x-input-label :for="$prefix.'-theorie_ort'" value="Ort" class="sr-only" />
                <x-text-input :id="$prefix.'-theorie_ort'" name="theorie_ort" type="text" placeholder="z. B. Theoriezentrum Innenstadt"
                              class="mt-1 block w-full" :value="old('theorie_ort', $location?->theorie_ort)" />
                <x-input-error :messages="$errors->get('theorie_ort')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$prefix.'-theorie_strasse'" value="Straße & Nr." class="sr-only" />
                <x-text-input :id="$prefix.'-theorie_strasse'" name="theorie_strasse" type="text" placeholder="z. B. Hafenstraße 12"
                              class="mt-1 block w-full" :value="old('theorie_strasse', $location?->theorie_strasse)" />
                <x-input-error :messages="$errors->get('theorie_strasse')" class="mt-2" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <x-input-label :for="$prefix.'-theorie_plz'" value="PLZ" class="sr-only" />
                    <x-text-input :id="$prefix.'-theorie_plz'" name="theorie_plz" type="text" placeholder="20095"
                                  class="mt-1 block w-full" :value="old('theorie_plz', $location?->theorie_plz)" />
                    <x-input-error :messages="$errors->get('theorie_plz')" class="mt-2" />
                </div>
                <div>
                    <x-input-label :for="$prefix.'-theorie_stadt'" value="Stadt" class="sr-only" />
                    <x-text-input :id="$prefix.'-theorie_stadt'" name="theorie_stadt" type="text" placeholder="Hamburg"
                                  class="mt-1 block w-full" :value="old('theorie_stadt', $location?->theorie_stadt)" />
                    <x-input-error :messages="$errors->get('theorie_stadt')" class="mt-2" />
                </div>
            </div>
            <div>
                <x-input-label :for="$prefix.'-theorie_land'" value="Land" class="sr-only" />
                <x-text-input :id="$prefix.'-theorie_land'" name="theorie_land" type="text" placeholder="Deutschland"
                              class="mt-1 block w-full" :value="old('theorie_land', $location?->theorie_land)" />
                <x-input-error :messages="$errors->get('theorie_land')" class="mt-2" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <x-input-label :for="$prefix.'-theorie_lat'" value="Breitengrad" class="sr-only" />
                    <x-text-input :id="$prefix.'-theorie_lat'" name="theorie_lat" type="text" inputmode="decimal" placeholder="53.5460740"
                                  class="mt-1 block w-full" :value="old('theorie_lat', $location?->theorie_lat)" />
                    <x-input-error :messages="$errors->get('theorie_lat')" class="mt-2" />
                </div>
                <div>
                    <x-input-label :for="$prefix.'-theorie_lng'" value="Längengrad" class="sr-only" />
                    <x-text-input :id="$prefix.'-theorie_lng'" name="theorie_lng" type="text" inputmode="decimal" placeholder="9.9826940"
                                  class="mt-1 block w-full" :value="old('theorie_lng', $location?->theorie_lng)" />
                    <x-input-error :messages="$errors->get('theorie_lng')" class="mt-2" />
                </div>
            </div>
        </div>
    </div>

    <div class="border-t border-slate-100 dark:border-slate-700 pt-4">
        <x-input-label :for="$prefix.'-pruefungsausschuss_id'" value="Prüfungsausschuss (optional)" />
        <p class="text-xs text-slate-400 mt-1 mb-2">
            Zuständige Prüfungskommission für diesen Ort -- feste Adresse aus der bundesweiten DMYV-Liste.
        </p>
        <select id="{{ $prefix }}-pruefungsausschuss_id" name="pruefungsausschuss_id" x-model="pruefungsausschussId"
                class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
            <option value="">&mdash; keiner zugeordnet &mdash;</option>
            @foreach ($pruefungsausschuesse as $pa)
                <option value="{{ $pa->id }}" @selected($initialPruefungsausschussId === $pa->id)>{{ $pa->label() }}</option>
            @endforeach
        </select>
        <x-input-error :messages="$errors->get('pruefungsausschuss_id')" class="mt-2" />

        <template x-if="pruefungsausschussId && pruefungsausschussInfo[pruefungsausschussId]">
            <div class="mt-2 text-xs text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-900/40 rounded-lg p-3 space-y-1">
                <p x-show="pruefungsausschussInfo[pruefungsausschussId].adresse" x-text="pruefungsausschussInfo[pruefungsausschussId].adresse"></p>
                <template x-if="pruefungsausschussInfo[pruefungsausschussId].kurse.length">
                    <p>Zuständig für: <span x-text="pruefungsausschussInfo[pruefungsausschussId].kurse.join(', ')"></span></p>
                </template>
            </div>
        </template>
    </div>

    <div>
        <x-input-label value="Für diese Kurse verfügbar" />
        @php($selectedCourseIds = old('course_ids', $location?->course_ids ?? []))
        <div class="mt-1 grid sm:grid-cols-2 gap-x-4 gap-y-1.5 max-h-56 overflow-y-auto border border-slate-200 dark:border-slate-700 rounded-lg p-3">
            @foreach ($courses as $course)
                @php($courseDisabled = $disabledCourseIds->contains($course->id))
                {{-- In der Kursauswahl abgewählte Kurse bleiben hier wählbar
                     und speicherbar (z. B. schon für eine spätere
                     Reaktivierung), werden aber ausgegraut -- so lange der
                     Kurs dort abgewählt bleibt, taucht er für Lernende im
                     Praxisformular ohnehin nicht auf. --}}
                <label @class(['inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300', 'opacity-50' => $courseDisabled])>
                    <input type="checkbox" name="course_ids[]" value="{{ $course->id }}" @checked(in_array($course->id, $selectedCourseIds, true))
                           class="rounded border-slate-300 dark:bg-slate-700 dark:border-slate-600">
                    {{ $course->name }}
                    @if ($courseDisabled)
                        <span class="text-xs">(abgewählt)</span>
                    @endif
                </label>
            @endforeach
        </div>
        <x-input-error :messages="$errors->get('course_ids')" class="mt-2" />
    </div>
</div>
