@php($tip = $tip ?? null)

<div class="grid sm:grid-cols-2 gap-4">
    <div>
        <x-input-label for="category_id" value="Kategorie" />
        <select id="category_id" name="category_id" required
                class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
            <option value="" disabled @selected(! old('category_id', $tip?->category_id))>Bitte wählen…</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected(old('category_id', $tip?->category_id) === $category->id)>{{ $category->name }}</option>
            @endforeach
        </select>
        @if ($categories->isEmpty())
            <p class="text-xs text-amber-600 mt-1">Noch keine Kategorie angelegt — <a href="{{ route('superadmin.tips.categories.index') }}" class="underline">jetzt anlegen</a>.</p>
        @endif
        <x-input-error :messages="$errors->get('category_id')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="sort_order" value="Reihenfolge" />
        <x-text-input id="sort_order" name="sort_order" type="number" class="mt-1 block w-full" :value="old('sort_order', $tip?->sort_order ?? 0)" />
        <x-input-error :messages="$errors->get('sort_order')" class="mt-2" />
    </div>
</div>

<div>
    <x-input-label for="title" value="Titel" />
    <x-text-input id="title" name="title" type="text" class="mt-1 block w-full" :value="old('title', $tip?->title)" required />
    <x-input-error :messages="$errors->get('title')" class="mt-2" />
</div>

<div>
    <x-input-label for="body" value="Inhalt" />
    <textarea id="body" name="body" rows="10" required
              class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">{{ old('body', $tip?->body) }}</textarea>
    <x-input-error :messages="$errors->get('body')" class="mt-2" />
</div>

<div>
    <x-input-label for="pdf" value="PDF-Anhang (optional)" />
    @if ($tip?->pdfAsset)
        <p class="text-xs text-slate-500 mb-1">
            Aktuell hinterlegt: <a href="{{ $tip->pdfAsset->storage_path }}" target="_blank" class="text-blue-600 hover:underline">PDF ansehen</a> — beim Hochladen einer neuen Datei wird diese ersetzt.
        </p>
    @endif
    <input id="pdf" name="pdf" type="file" accept="application/pdf" class="mt-1 block w-full text-sm text-slate-500 dark:text-slate-400">
    <x-input-error :messages="$errors->get('pdf')" class="mt-2" />
</div>
