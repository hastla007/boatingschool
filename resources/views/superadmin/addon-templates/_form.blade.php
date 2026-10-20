@php($template = $template ?? null)

<div class="grid sm:grid-cols-2 gap-4">
    <div>
        <x-input-label for="title" value="Titel (intern)" />
        <x-text-input id="title" name="title" type="text" class="mt-1 block w-full" :value="old('title', $template?->title)" required />
        <x-input-error :messages="$errors->get('title')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="sort_order" value="Reihenfolge" />
        <x-text-input id="sort_order" name="sort_order" type="number" class="mt-1 block w-full" :value="old('sort_order', $template?->sort_order ?? 0)" />
        <x-input-error :messages="$errors->get('sort_order')" class="mt-2" />
    </div>
</div>

<div class="grid sm:grid-cols-2 gap-4">
    <div>
        <x-input-label for="badge_text" value="Badge (z. B. „BESTSELLER&quot;)" />
        <x-text-input id="badge_text" name="badge_text" type="text" class="mt-1 block w-full" :value="old('badge_text', $template?->badge_text)" />
        <x-input-error :messages="$errors->get('badge_text')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="stat_text" value="Stat-Zeile (z. B. „81% wählen diese Option&quot;)" />
        <x-text-input id="stat_text" name="stat_text" type="text" class="mt-1 block w-full" :value="old('stat_text', $template?->stat_text)" />
        <x-input-error :messages="$errors->get('stat_text')" class="mt-2" />
    </div>
</div>

<div>
    <x-input-label for="headline" value="Headline des Banners" />
    <x-text-input id="headline" name="headline" type="text" class="mt-1 block w-full" :value="old('headline', $template?->headline)" required />
    <x-input-error :messages="$errors->get('headline')" class="mt-2" />
</div>

<div>
    <x-input-label for="bullets" value="Haken-Bullets (eine Zeile pro Punkt)" />
    <textarea id="bullets" name="bullets" rows="5"
              class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">{{ old('bullets', $template ? implode("\n", $template->bullets ?? []) : '') }}</textarea>
    <x-input-error :messages="$errors->get('bullets')" class="mt-2" />
</div>

<div>
    <x-input-label for="cta_label" value="Button-Text" />
    <x-text-input id="cta_label" name="cta_label" type="text" class="mt-1 block w-full" :value="old('cta_label', $template?->cta_label ?? 'Jetzt sichern')" required />
    <x-input-error :messages="$errors->get('cta_label')" class="mt-2" />
</div>
