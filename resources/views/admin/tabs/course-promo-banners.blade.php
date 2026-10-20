<p class="text-slate-500 mb-6">
    Hier kannst du pro Kurs einen Banner aktivieren, der auf dessen Kursseite für einen anderen oder
    weiterführenden Kurs wirbt (z. B. einen Ergänzungskurs). Überschrift und Beschreibungstext schreibst du
    frei selbst, Preis, Rabatt in % und Gutscheincode sind optional (reine Anzeigefelder &mdash; der Rabatt-VK
    wird daraus automatisch berechnet), der Link führt zu deiner externen Angebotsseite.
</p>

<form method="POST" action="{{ route('admin.course-promo-banners.update') }}" class="space-y-6">
    @csrf
    @method('PATCH')

    @foreach ($courses as $course)
        @php($config = $coursePromoBanners->get($course->id))
        <div class="max-w-2xl bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 space-y-4" x-data="{ active: {{ old('banners.'.$course->id.'.active', $config?->active ? 'true' : 'false') }} }">
            <div class="flex items-center justify-between">
                <div class="font-medium text-slate-700 dark:text-slate-200">{{ $course->name }}</div>
                <label class="inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="hidden" name="banners[{{ $course->id }}][active]" value="0">
                    <input type="checkbox" name="banners[{{ $course->id }}][active]" value="1" x-model="active"
                           class="rounded border-slate-300 dark:bg-slate-700 dark:border-slate-600">
                    Aktiviert
                </label>
            </div>

            <div x-show="active" class="space-y-4" style="display: none">
                <div>
                    <x-input-label :for="'headline_'.$course->id" value="Überschrift" />
                    <x-text-input :id="'headline_'.$course->id" name="banners[{{ $course->id }}][headline]" type="text" placeholder="z. B. Mach weiter mit der Segelausbildung!"
                                  class="mt-1 block w-full" :value="old('banners.'.$course->id.'.headline', $config?->headline)" />
                    <x-input-error :messages="$errors->get('banners.'.$course->id.'.headline')" class="mt-2" />
                </div>
                <div>
                    <x-input-label :for="'description_'.$course->id" value="Beschreibungstext (optional)" />
                    <textarea id="{{ 'description_'.$course->id }}" name="banners[{{ $course->id }}][description]" rows="2"
                              class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">{{ old('banners.'.$course->id.'.description', $config?->description) }}</textarea>
                    <x-input-error :messages="$errors->get('banners.'.$course->id.'.description')" class="mt-2" />
                </div>
                <div>
                    <x-input-label :for="'cta_label_'.$course->id" value="Button-Text" />
                    <x-text-input :id="'cta_label_'.$course->id" name="banners[{{ $course->id }}][cta_label]" type="text" placeholder="Jetzt entdecken"
                                  class="mt-1 block w-full" :value="old('banners.'.$course->id.'.cta_label', $config?->cta_label ?? 'Jetzt entdecken')" />
                    <x-input-error :messages="$errors->get('banners.'.$course->id.'.cta_label')" class="mt-2" />
                </div>
                <div class="grid sm:grid-cols-3 gap-4">
                    <div>
                        <x-input-label :for="'price_label_'.$course->id" value="Preis normal (optional)" />
                        <x-text-input :id="'price_label_'.$course->id" name="banners[{{ $course->id }}][price_label]" type="text" placeholder="z. B. 149 €"
                                      class="mt-1 block w-full" :value="old('banners.'.$course->id.'.price_label', $config?->price_label)" />
                        <x-input-error :messages="$errors->get('banners.'.$course->id.'.price_label')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label :for="'discount_percent_'.$course->id" value="Rabatt in % (optional)" />
                        <x-text-input :id="'discount_percent_'.$course->id" name="banners[{{ $course->id }}][discount_percent]" type="number" min="0" max="100" placeholder="z. B. 20"
                                      class="mt-1 block w-full" :value="old('banners.'.$course->id.'.discount_percent', $config?->discount_percent)" />
                        <x-input-error :messages="$errors->get('banners.'.$course->id.'.discount_percent')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label :for="'coupon_code_'.$course->id" value="Gutscheincode (optional)" />
                        <x-text-input :id="'coupon_code_'.$course->id" name="banners[{{ $course->id }}][coupon_code]" type="text"
                                      class="mt-1 block w-full" :value="old('banners.'.$course->id.'.coupon_code', $config?->coupon_code)" />
                        <x-input-error :messages="$errors->get('banners.'.$course->id.'.coupon_code')" class="mt-2" />
                    </div>
                </div>
                <div>
                    <x-input-label :for="'offer_url_'.$course->id" value="Link zu deiner Angebotsseite" />
                    <x-text-input :id="'offer_url_'.$course->id" name="banners[{{ $course->id }}][offer_url]" type="url" placeholder="https://dein-webshop.de/angebot/..."
                                  class="mt-1 block w-full" :value="old('banners.'.$course->id.'.offer_url', $config?->offer_url)" />
                    <x-input-error :messages="$errors->get('banners.'.$course->id.'.offer_url')" class="mt-2" />
                </div>
            </div>
        </div>
    @endforeach

    <button type="submit" class="px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
        Änderungen speichern
    </button>
</form>
