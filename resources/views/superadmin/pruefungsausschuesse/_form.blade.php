@php($pa = $pruefungsausschuss ?? null)

<div class="grid sm:grid-cols-2 gap-4">
    <div>
        <x-input-label for="kommission" value="Kommission" />
        <x-text-input id="kommission" name="kommission" type="text" class="mt-1 block w-full" :value="old('kommission', $pa?->kommission)" required />
        <x-input-error :messages="$errors->get('kommission')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="standorttyp" value="Standorttyp (z. B. „Geschäftsstelle&quot;, „Postanschrift&quot;)" />
        <x-text-input id="standorttyp" name="standorttyp" type="text" class="mt-1 block w-full" :value="old('standorttyp', $pa?->standorttyp)" />
        <x-input-error :messages="$errors->get('standorttyp')" class="mt-2" />
    </div>
</div>

<div>
    <x-input-label for="kontaktperson" value="Kontaktperson" />
    <x-text-input id="kontaktperson" name="kontaktperson" type="text" class="mt-1 block w-full" :value="old('kontaktperson', $pa?->kontaktperson)" />
    <x-input-error :messages="$errors->get('kontaktperson')" class="mt-2" />
</div>

<div>
    <x-input-label for="strasse" value="Straße & Nr." />
    <x-text-input id="strasse" name="strasse" type="text" class="mt-1 block w-full" :value="old('strasse', $pa?->strasse)" />
    <x-input-error :messages="$errors->get('strasse')" class="mt-2" />
</div>

<div class="grid sm:grid-cols-2 gap-4">
    <div>
        <x-input-label for="plz" value="PLZ" />
        <x-text-input id="plz" name="plz" type="text" class="mt-1 block w-full" :value="old('plz', $pa?->plz)" />
        <x-input-error :messages="$errors->get('plz')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="stadt" value="Stadt" />
        <x-text-input id="stadt" name="stadt" type="text" class="mt-1 block w-full" :value="old('stadt', $pa?->stadt)" />
        <x-input-error :messages="$errors->get('stadt')" class="mt-2" />
    </div>
</div>

<div class="grid sm:grid-cols-2 gap-4">
    <div>
        <x-input-label for="telefon" value="Telefon" />
        <x-text-input id="telefon" name="telefon" type="text" class="mt-1 block w-full" :value="old('telefon', $pa?->telefon)" />
        <x-input-error :messages="$errors->get('telefon')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="fax" value="Fax" />
        <x-text-input id="fax" name="fax" type="text" class="mt-1 block w-full" :value="old('fax', $pa?->fax)" />
        <x-input-error :messages="$errors->get('fax')" class="mt-2" />
    </div>
</div>

<div class="grid sm:grid-cols-2 gap-4">
    <div>
        <x-input-label for="email" value="E-Mail" />
        <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $pa?->email)" />
        <x-input-error :messages="$errors->get('email')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="website" value="Website" />
        <x-text-input id="website" name="website" type="text" class="mt-1 block w-full" :value="old('website', $pa?->website)" />
        <x-input-error :messages="$errors->get('website')" class="mt-2" />
    </div>
</div>

<div class="grid sm:grid-cols-2 gap-4">
    <div>
        <x-input-label for="sprechzeiten" value="Sprechzeiten" />
        <x-text-input id="sprechzeiten" name="sprechzeiten" type="text" class="mt-1 block w-full" :value="old('sprechzeiten', $pa?->sprechzeiten)" />
        <x-input-error :messages="$errors->get('sprechzeiten')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="sort_order" value="Reihenfolge" />
        <x-text-input id="sort_order" name="sort_order" type="number" class="mt-1 block w-full" :value="old('sort_order', $pa?->sort_order ?? 0)" />
        <x-input-error :messages="$errors->get('sort_order')" class="mt-2" />
    </div>
</div>

<div>
    <x-input-label for="hinweis" value="Hinweis (optional)" />
    <textarea id="hinweis" name="hinweis" rows="3"
              class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">{{ old('hinweis', $pa?->hinweis) }}</textarea>
    <x-input-error :messages="$errors->get('hinweis')" class="mt-2" />
</div>
