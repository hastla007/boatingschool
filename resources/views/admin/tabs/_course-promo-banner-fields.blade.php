@php($banner = $banner ?? null)

<div>
    <x-input-label :for="$prefix.'-headline'" value="Überschrift" />
    <x-text-input :id="$prefix.'-headline'" name="headline" type="text" placeholder="z. B. Mach weiter mit der Segelausbildung!"
                  class="mt-1 block w-full" :value="old('headline', $banner?->headline)" />
    <x-input-error :messages="$errors->get('headline')" class="mt-2" />
</div>
<div>
    <x-input-label :for="$prefix.'-description'" value="Beschreibungstext (optional)" />
    <textarea id="{{ $prefix.'-description' }}" name="description" rows="2"
              class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">{{ old('description', $banner?->description) }}</textarea>
    <x-input-error :messages="$errors->get('description')" class="mt-2" />
</div>
<div>
    <x-input-label :for="$prefix.'-bullets'" value="Haken-Bullets (eine Zeile pro Punkt, optional)" />
    <textarea id="{{ $prefix.'-bullets' }}" name="bullets" rows="4"
              class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">{{ old('bullets', implode("\n", $banner?->bullets ?? [])) }}</textarea>
    <x-input-error :messages="$errors->get('bullets')" class="mt-2" />
</div>
<div>
    <x-input-label :for="$prefix.'-cta_label'" value="Button-Text" />
    <x-text-input :id="$prefix.'-cta_label'" name="cta_label" type="text" placeholder="Jetzt entdecken"
                  class="mt-1 block w-full" :value="old('cta_label', $banner?->cta_label ?? 'Jetzt entdecken')" />
    <x-input-error :messages="$errors->get('cta_label')" class="mt-2" />
</div>
<div class="grid sm:grid-cols-3 gap-4">
    <div>
        <x-input-label :for="$prefix.'-price_label'" value="Preis normal (optional)" />
        <x-text-input :id="$prefix.'-price_label'" name="price_label" type="text" placeholder="z. B. 149 €"
                      class="mt-1 block w-full" :value="old('price_label', $banner?->price_label)" />
        <x-input-error :messages="$errors->get('price_label')" class="mt-2" />
    </div>
    <div>
        <x-input-label :for="$prefix.'-discount_percent'" value="Rabatt in % (optional)" />
        <x-text-input :id="$prefix.'-discount_percent'" name="discount_percent" type="number" min="0" max="100" placeholder="z. B. 20"
                      class="mt-1 block w-full" :value="old('discount_percent', $banner?->discount_percent)" />
        <x-input-error :messages="$errors->get('discount_percent')" class="mt-2" />
    </div>
    <div>
        <x-input-label :for="$prefix.'-coupon_code'" value="Gutscheincode (optional)" />
        <x-text-input :id="$prefix.'-coupon_code'" name="coupon_code" type="text"
                      class="mt-1 block w-full" :value="old('coupon_code', $banner?->coupon_code)" />
        <x-input-error :messages="$errors->get('coupon_code')" class="mt-2" />
    </div>
</div>
<div>
    <x-input-label :for="$prefix.'-offer_url'" value="Link zu Ihrer Angebotsseite" />
    <x-text-input :id="$prefix.'-offer_url'" name="offer_url" type="url" placeholder="https://ihr-webshop.de/angebot/..."
                  class="mt-1 block w-full" :value="old('offer_url', $banner?->offer_url)" />
    <x-input-error :messages="$errors->get('offer_url')" class="mt-2" />
</div>
<div>
    <x-input-label value="Auf diesen Kursseiten anzeigen" />
    @php($selectedCourseIds = old('course_ids', $banner?->course_ids ?? []))
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
<div>
    <x-input-label :for="$prefix.'-trigger_percent'" value="Sichtbar ab % Kursfortschritt (je ausgewähltem Kurs, 0 = sofort sichtbar)" />
    <x-text-input :id="$prefix.'-trigger_percent'" name="trigger_percent" type="number" min="0" max="100"
                  class="mt-1 block w-full sm:w-48" :value="old('trigger_percent', $banner?->trigger_percent ?? 0)" />
    <x-input-error :messages="$errors->get('trigger_percent')" class="mt-2" />
</div>
