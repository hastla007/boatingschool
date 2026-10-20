@php($location = $location ?? null)

<div x-data="{ sameAsLocation: {{ ! $location || $location->praxis_ort === $location->pruefung_ort ? 'true' : 'false' }} }" class="space-y-4">
    <div class="grid sm:grid-cols-2 gap-4">
        <div>
            <x-input-label :for="$prefix.'-praxis_ort'" value="Praxisort" />
            <x-text-input :id="$prefix.'-praxis_ort'" name="praxis_ort" type="text" placeholder="z. B. Hauptstandort"
                          class="mt-1 block w-full" :value="old('praxis_ort', $location?->praxis_ort)" required />
            <x-input-error :messages="$errors->get('praxis_ort')" class="mt-2" />
        </div>
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
