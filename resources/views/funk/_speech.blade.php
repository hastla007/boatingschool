{{-- Sprachein- und -ausgabe des Browsers (Web Speech API): Einsprechen der
     Meldung und Vorlesen von Vokabeln/Seefunktexten. Ohne Unterstützung im
     Browser blenden sich die Knöpfe aus (x-show="supported"). Es wird nichts
     an den Server übertragen außer dem, was im Textfeld landet. Die
     Spracherkennung startet erst nach Einwilligung (funk._speech-consent):
     der Browser schickt die Aufnahme an Google bzw. Apple. --}}
@include('funk._speech-consent')
@once
    <script nonce="{{ $cspNonce }}">
        window.funkDictation = function (lang) {
            const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            return {
                supported: !! Recognition,
                active: false,
                recognition: null,
                async toggle(field) {
                    if (this.active) { this.recognition.stop(); return; }
                    // Erst nach Einwilligung (Dialog beim ersten Mal).
                    if (! window.speechConsent || ! await window.speechConsent.ensure()) { return; }
                    this.recognition = new Recognition();
                    this.recognition.lang = lang;
                    this.recognition.continuous = true;
                    this.recognition.interimResults = false;
                    this.recognition.onresult = (event) => {
                        for (let i = event.resultIndex; i < event.results.length; i++) {
                            if (event.results[i].isFinal) {
                                field.value = (field.value.trim() + ' ' + event.results[i][0].transcript.trim()).trim();
                                field.dispatchEvent(new Event('input', { bubbles: true }));
                            }
                        }
                    };
                    this.recognition.onend = () => { this.active = false; };
                    this.recognition.start();
                    this.active = true;
                },
            };
        };
        window.funkSpeak = function (text, lang, rate = 0.95) {
            if (! ('speechSynthesis' in window)) { return; }
            window.speechSynthesis.cancel();
            const utterance = new SpeechSynthesisUtterance(text);
            utterance.lang = lang;
            utterance.rate = rate;
            window.speechSynthesis.speak(utterance);
        };
    </script>
@endonce
