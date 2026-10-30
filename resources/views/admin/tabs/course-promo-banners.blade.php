{{-- Bug-Review (Du/Sie im Admin-Bereich gemischt): der restliche
     Admin-Bereich siezt durchgehend. --}}
<p class="text-slate-500 dark:text-slate-400 mb-6">
    Hier können Sie frei formulierte Banner anlegen, die für einen anderen oder weiterführenden Kurs werben
    (z. B. einen Ergänzungskurs). Überschrift, Beschreibungstext und Haken-Bullets schreiben Sie frei selbst,
    Preis, Rabatt in % und Gutscheincode sind optional (reine Anzeigefelder &mdash; der Rabatt-VK wird daraus
    automatisch berechnet), der Link führt zu Ihrer externen Angebotsseite. Ein Banner kann gleichzeitig auf
    mehreren Kursseiten erscheinen &mdash; wählen Sie dafür unten alle passenden Kurse aus. Optional blenden Sie den
    Banner erst ab einem bestimmten Kursfortschritt ein (gilt dann für jeden ausgewählten Kurs einzeln).
</p>

<div class="space-y-6" x-data="{ showCreateForm: {{ $errors->has('headline') && old('_form') === 'create' ? 'true' : 'false' }} }">
    @foreach ($coursePromoBanners as $banner)
        <div class="max-w-2xl bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 space-y-4">
            <form method="POST" action="{{ route('admin.course-promo-banners.update', $banner) }}" class="space-y-4">
                @csrf
                @method('PATCH')
                <div class="flex items-center justify-between">
                    <div class="font-medium text-slate-700 dark:text-slate-200">{{ $banner->headline }}</div>
                    <div class="flex items-center gap-3">
                        <label class="inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                            <input type="hidden" name="active" value="0">
                            <input type="checkbox" name="active" value="1" @checked($banner->active)
                                   class="rounded border-slate-300 dark:bg-slate-700 dark:border-slate-600">
                            Aktiviert
                        </label>
                    </div>
                </div>

                @include('admin.tabs._course-promo-banner-fields', ['banner' => $banner, 'prefix' => 'banner-'.$banner->id])

                <div class="flex items-center justify-between pt-2">
                    <button type="submit" class="px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                        Änderungen speichern
                    </button>
                </div>
            </form>
            <form method="POST" action="{{ route('admin.course-promo-banners.destroy', $banner) }}" @submit="if (! confirm('Diesen Banner wirklich löschen?')) $event.preventDefault()">
                @csrf
                @method('DELETE')
                <button type="submit" class="text-xs text-red-500 hover:underline">Banner löschen</button>
            </form>
        </div>
    @endforeach

    <div class="max-w-2xl">
        <button type="button" @click="showCreateForm = ! showCreateForm" x-show="! showCreateForm"
                class="px-5 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 text-sm font-medium hover:bg-slate-50 dark:hover:bg-slate-700 transition">
            + Neuen Banner anlegen
        </button>

        <div x-show="showCreateForm" style="display: none" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 space-y-4">
            <form method="POST" action="{{ route('admin.course-promo-banners.store') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="_form" value="create">
                <label class="inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="hidden" name="active" value="0">
                    <input type="checkbox" name="active" value="1" checked
                           class="rounded border-slate-300 dark:bg-slate-700 dark:border-slate-600">
                    Aktiviert
                </label>

                @include('admin.tabs._course-promo-banner-fields', ['banner' => null, 'prefix' => 'new'])

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                        Banner anlegen
                    </button>
                    <button type="button" @click="showCreateForm = false" class="text-sm text-slate-500 dark:text-slate-400 hover:underline">
                        Abbrechen
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
