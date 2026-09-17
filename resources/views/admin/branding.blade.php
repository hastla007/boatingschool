<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Branding-Einstellungen</h2>
    </x-slot>

    <p class="text-slate-500 mb-6">Passen Sie das Erscheinungsbild Ihrer Bootsschule an.</p>

    <div class="max-w-lg bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6">
        <form method="POST" action="{{ route('admin.branding.update') }}" enctype="multipart/form-data" class="space-y-5">
            @csrf
            @method('PATCH')

            <div>
                <label class="block text-sm font-medium text-slate-600 dark:text-slate-300 mb-2">Logo</label>
                <div class="flex items-center gap-4">
                    <div class="w-16 h-16 rounded-xl bg-slate-100 dark:bg-slate-700 flex items-center justify-center overflow-hidden shrink-0">
                        @if ($branding->logo_asset_id && $branding->logoAsset)
                            <img src="{{ $branding->logoAsset->storage_path }}" alt="Logo" class="w-full h-full object-contain">
                        @else
                            <x-icon name="anchor" class="w-7 h-7 text-slate-300" />
                        @endif
                    </div>
                    <label class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-sm text-slate-600 dark:text-slate-300 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-700 transition">
                        <x-icon name="upload" class="w-4 h-4" />
                        <span>Logo hochladen</span>
                        <input type="file" name="logo" accept="image/*" class="hidden" onchange="this.form.requestSubmit ? null : null; document.getElementById('logo-filename').textContent = this.files[0]?.name ?? ''">
                    </label>
                    <span id="logo-filename" class="text-xs text-slate-400"></span>
                </div>
                <x-input-error :messages="$errors->get('logo')" class="mt-2" />
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-600 dark:text-slate-300 mb-1">Name</label>
                <input type="text" value="{{ $tenant->name }}" disabled class="w-full rounded-lg border-slate-200 bg-slate-50 dark:bg-slate-700 text-sm text-slate-400">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-600 dark:text-slate-300 mb-1">Domain</label>
                <input type="text" value="{{ $tenant->slug }}.{{ config('app.central_domain') }}" disabled class="w-full rounded-lg border-slate-200 bg-slate-50 dark:bg-slate-700 text-sm text-slate-400">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-600 dark:text-slate-300 mb-1">Primärfarbe</label>
                    <div class="flex items-center gap-2">
                        <input type="color" name="primary_color" value="{{ old('primary_color', $branding->primary_color) }}" class="w-10 h-10 rounded-lg border-slate-300 shrink-0 p-0.5">
                        <span class="text-xs text-slate-400 font-mono">{{ old('primary_color', $branding->primary_color) }}</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600 dark:text-slate-300 mb-1">Sekundärfarbe</label>
                    <div class="flex items-center gap-2">
                        <input type="color" name="secondary_color" value="{{ old('secondary_color', $branding->secondary_color) }}" class="w-10 h-10 rounded-lg border-slate-300 shrink-0 p-0.5">
                        <span class="text-xs text-slate-400 font-mono">{{ old('secondary_color', $branding->secondary_color) }}</span>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-600 dark:text-slate-300 mb-1">Support E-Mail</label>
                <input type="email" name="support_email" value="{{ old('support_email', $branding->support_email) }}" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-600 dark:text-slate-300 mb-1">Rechtlicher Name</label>
                <input type="text" name="legal_name" value="{{ old('legal_name', $branding->legal_name) }}" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
            </div>

            <div class="pt-2 border-t border-slate-100 dark:border-slate-700">
                <label class="block text-sm font-medium text-slate-600 dark:text-slate-300 mb-1">Kursfortschritt-Voraussetzung für Prüfung &amp; Praxis</label>
                <p class="text-xs text-slate-400 mb-2">Ab diesem Kursfortschritt darf ein Schüler die Kachel „Praxis &amp; Prüfung" auswählen, um Prüfung und Praxis bei Ihrer Bootsschule zu buchen.</p>
                <div class="flex items-center gap-2">
                    <input type="number" name="exam_readiness_threshold_percent" min="0" max="100"
                           value="{{ old('exam_readiness_threshold_percent', $branding->exam_readiness_threshold_percent) }}"
                           class="w-24 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                    <span class="text-sm text-slate-500 dark:text-slate-400">% Kursfortschritt</span>
                </div>
                <x-input-error :messages="$errors->get('exam_readiness_threshold_percent')" class="mt-2" />
            </div>

            <button type="submit" class="px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                Änderungen speichern
            </button>
        </form>
    </div>
</x-app-layout>
