{{-- Pflicht-Checkbox Mindestalter/Einverständnis (App\Support\AgeConfirmation). --}}
<div>
    <label for="age_confirmed" class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
        <input id="age_confirmed" type="checkbox" name="age_confirmed" value="1" required @checked(old('age_confirmed')) class="mt-0.5 rounded border-slate-300 dark:bg-slate-700 dark:border-slate-600">
        <span>{{ \App\Support\AgeConfirmation::LABEL }}</span>
    </label>
    <x-input-error :messages="$errors->get('age_confirmed')" class="mt-2" />
</div>
