{{-- Einwilligung in die Spracherkennung des Browsers (SpeechConsentController):
     vor der ersten Nutzung fragt dieser Dialog. Ohne Einwilligung bleibt die
     Spracherkennung aus (Eintippen geht immer). window.speechConsent.ensure()
     liefert ein Promise<boolean>; „Nein“ gilt bis zum Neuladen der Seite. --}}
@once
    <dialog id="speech-consent-dialog" aria-labelledby="speech-consent-title" class="rounded-2xl p-0 max-w-md w-[calc(100%-2rem)] shadow-xl backdrop:bg-black/50">
        <div class="p-6 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 space-y-3 text-sm">
            <h2 id="speech-consent-title" class="text-lg font-semibold text-slate-900 dark:text-white">Spracherkennung nutzen?</h2>
            <p>
                Wenn du einen Funkspruch einsprichst, wandelt die <strong>Spracherkennung deines Browsers</strong> deine Stimme in Text um.
                Dafür schickt der Browser die Aufnahme an seinen Anbieter: <strong>Chrome und Edge an Google</strong>, <strong>Safari an Apple</strong>.
                Das geschieht nur, solange du sprichst bzw. die Sprechtaste hältst. Bei uns kommt nur der erkannte Text an.
            </p>
            <p>
                Ohne Einwilligung bleibt die Spracherkennung aus – du kannst Funksprüche jederzeit eintippen.
                Deine Einwilligung kannst du in deinem Profil unter „Spracherkennung“ widerrufen.
                Mehr dazu in der <a href="{{ route('legal.show', 'datenschutz') }}" target="_blank" rel="noopener" class="underline">Datenschutzerklärung</a> (Abschnitt Spracherkennung).
            </p>
            <p data-speech-consent-error class="hidden text-red-700 dark:text-red-300">Die Einwilligung konnte nicht gespeichert werden. Bitte versuche es später erneut.</p>
            <div class="flex flex-wrap justify-end gap-2 pt-2">
                <button type="button" data-speech-consent="decline" class="px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 hover:bg-slate-50 dark:hover:bg-slate-700">Nein, lieber tippen</button>
                <button type="button" data-speech-consent="accept" class="px-4 py-2 rounded-lg font-semibold text-white hover:opacity-90" style="background-color: var(--brand-primary, #005FD7)">Einverstanden</button>
            </div>
        </div>
    </dialog>
    <script nonce="{{ $cspNonce }}">
        window.speechConsent = (function () {
            const dialog = document.getElementById('speech-consent-dialog');
            const error = dialog.querySelector('[data-speech-consent-error]');
            const state = {
                granted: @json(auth()->user()?->speech_consent_at !== null),
                declined: false,
                pending: null,
                ensure() {
                    if (state.granted) { return Promise.resolve(true); }
                    if (state.pending) { return state.pending; }
                    state.pending = new Promise((resolve) => {
                        const finish = (value) => {
                            dialog.close();
                            state.pending = null;
                            resolve(value);
                        };
                        dialog.querySelector('[data-speech-consent="decline"]').onclick = () => { state.declined = true; finish(false); };
                        dialog.oncancel = () => { state.declined = true; finish(false); };
                        dialog.querySelector('[data-speech-consent="accept"]').onclick = async () => {
                            error.classList.add('hidden');
                            try {
                                const response = await fetch(@json(route('profile.speech-consent.store')), {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    headers: {
                                        'Accept': 'application/json',
                                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                                    },
                                });
                                if (! response.ok) { throw new Error(String(response.status)); }
                                state.granted = true;
                                state.declined = false;
                                finish(true);
                            } catch (e) {
                                error.classList.remove('hidden');
                            }
                        };
                        error.classList.add('hidden');
                        dialog.showModal();
                    });

                    return state.pending;
                },
            };

            return state;
        })();
    </script>
@endonce
