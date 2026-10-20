<p class="text-slate-500 mb-6">
    Hier kannst du vorgefertigte Addon-Banner aktivieren, mit denen du Kursteilnehmern ein Upgrade oder
    Zusatzpaket anbietest. Für jedes aktivierte Addon hinterlegst du deinen Gutscheincode und Preis
    (beides reine Anzeigefelder &mdash; der Verkauf läuft über deinen eigenen Webshop), optional einen
    Rabatt in % (der rabattierte VK wird daraus automatisch berechnet und zusätzlich angezeigt), die
    Haken-Bullets (standardmäßig die der Vorlage, von dir frei änderbar), den Kurs und den Kursfortschritt,
    ab dem der Banner auf dessen Kursseite erscheint, sowie den Link zu deiner Angebotsseite.
</p>

@if ($addonTemplates->isEmpty())
    <div class="max-w-2xl bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 text-slate-500">
        Es sind aktuell keine Addon-Vorlagen verfügbar.
    </div>
@else
    <form method="POST" action="{{ route('admin.addons.update') }}" class="space-y-6">
        @csrf
        @method('PATCH')

        @foreach ($addonTemplates as $template)
            @php($config = $tenantAddons->get($template->id))
            <div class="max-w-2xl bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 space-y-4" x-data="{ active: {{ old('addons.'.$template->id.'.active', $config?->active ? 'true' : 'false') }} }">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="font-medium text-slate-700 dark:text-slate-200">{{ $template->title }}</div>
                        <div class="text-xs text-slate-400">{{ $template->headline }}</div>
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <input type="hidden" name="addons[{{ $template->id }}][active]" value="0">
                        <input type="checkbox" name="addons[{{ $template->id }}][active]" value="1" x-model="active"
                               class="rounded border-slate-300 dark:bg-slate-700 dark:border-slate-600">
                        Aktiviert
                    </label>
                </div>

                <div x-show="active" class="grid sm:grid-cols-2 gap-4" style="display: none">
                    <div>
                        <x-input-label :for="'coupon_code_'.$template->id" value="Gutscheincode (für den Banner)" />
                        <x-text-input :id="'coupon_code_'.$template->id" name="addons[{{ $template->id }}][coupon_code]" type="text"
                                      class="mt-1 block w-full" :value="old('addons.'.$template->id.'.coupon_code', $config?->coupon_code)" />
                        <x-input-error :messages="$errors->get('addons.'.$template->id.'.coupon_code')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label :for="'price_label_'.$template->id" value="Preis normal (ohne Rabatt)" />
                        <x-text-input :id="'price_label_'.$template->id" name="addons[{{ $template->id }}][price_label]" type="text" placeholder="z. B. 149 €"
                                      class="mt-1 block w-full" :value="old('addons.'.$template->id.'.price_label', $config?->price_label)" />
                        <x-input-error :messages="$errors->get('addons.'.$template->id.'.price_label')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label :for="'discount_percent_'.$template->id" value="Rabatt in % (optional)" />
                        <x-text-input :id="'discount_percent_'.$template->id" name="addons[{{ $template->id }}][discount_percent]" type="number" min="0" max="100" placeholder="z. B. 20"
                                      class="mt-1 block w-full" :value="old('addons.'.$template->id.'.discount_percent', $config?->discount_percent)" />
                        <x-input-error :messages="$errors->get('addons.'.$template->id.'.discount_percent')" class="mt-2" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label :for="'bullets_'.$template->id" value="Haken-Bullets (eine Zeile pro Punkt)" />
                        <textarea id="{{ 'bullets_'.$template->id }}" name="addons[{{ $template->id }}][bullets]" rows="4"
                                  class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">{{ old('addons.'.$template->id.'.bullets', implode("\n", $config?->effectiveBullets() ?? $template->bullets ?? [])) }}</textarea>
                        <p class="text-xs text-slate-400 mt-1">Vorausgefüllt mit den Punkten der Vorlage &mdash; du kannst sie anpassen, ergänzen oder löschen.</p>
                        <x-input-error :messages="$errors->get('addons.'.$template->id.'.bullets')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label :for="'trigger_course_id_'.$template->id" value="Sichtbar ab % Kursfortschritt von" />
                        <select id="{{ 'trigger_course_id_'.$template->id }}" name="addons[{{ $template->id }}][trigger_course_id]"
                                class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                            <option value="" @selected(! old('addons.'.$template->id.'.trigger_course_id', $config?->trigger_course_id))>Kein Kurs ausgewählt</option>
                            @foreach ($courses as $course)
                                <option value="{{ $course->id }}" @selected(old('addons.'.$template->id.'.trigger_course_id', $config?->trigger_course_id) === $course->id)>{{ $course->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('addons.'.$template->id.'.trigger_course_id')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label :for="'trigger_percent_'.$template->id" value="Sichtbar ab % Kursfortschritt" />
                        <x-text-input :id="'trigger_percent_'.$template->id" name="addons[{{ $template->id }}][trigger_percent]" type="number" min="0" max="100"
                                      class="mt-1 block w-full" :value="old('addons.'.$template->id.'.trigger_percent', $config?->trigger_percent ?? 0)" />
                        <x-input-error :messages="$errors->get('addons.'.$template->id.'.trigger_percent')" class="mt-2" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label :for="'offer_url_'.$template->id" value="Link zu deiner Angebotsseite" />
                        <x-text-input :id="'offer_url_'.$template->id" name="addons[{{ $template->id }}][offer_url]" type="url" placeholder="https://dein-webshop.de/angebot/..."
                                      class="mt-1 block w-full" :value="old('addons.'.$template->id.'.offer_url', $config?->offer_url)" />
                        <x-input-error :messages="$errors->get('addons.'.$template->id.'.offer_url')" class="mt-2" />
                    </div>
                </div>
            </div>
        @endforeach

        <button type="submit" class="px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
            Änderungen speichern
        </button>
    </form>
@endif
