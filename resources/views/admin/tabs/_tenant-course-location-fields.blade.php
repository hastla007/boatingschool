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

<div x-data="{ sameAsLocation: {{ $sameByDefault ? 'true' : 'false' }} }" class="space-y-4">
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

    <div>
        <x-input-label value="Für diese Kurse verfügbar" />
        @php($selectedCourseIds = old('course_ids', $location?->course_ids ?? []))
        <div class="mt-1 grid sm:grid-cols-2 gap-x-4 gap-y-1.5 max-h-56 overflow-y-auto border border-slate-200 dark:border-slate-700 rounded-lg p-3">
            @foreach ($courses as $course)
                <label class="inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" name="course_ids[]" value="{{ $course->id }}" @checked(in_array($course->id, $selectedCourseIds, true))
                           class="rounded border-slate-300 dark:bg-slate-700 dark:border-slate-600">
                    {{ $course->name }}
                </label>
            @endforeach
        </div>
        <x-input-error :messages="$errors->get('course_ids')" class="mt-2" />
    </div>
</div>
