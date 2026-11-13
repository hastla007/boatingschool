{{-- Bot-Schutz ohne Dritte (RegistrationBotCheck): Honeypot-Feld, das für
     Menschen unsichtbar bleibt, und signierter Zeitpunkt des Formularaufrufs. --}}
<input type="hidden" name="{{ \App\Support\RegistrationBotCheck::TIMESTAMP }}" value="{{ \App\Support\RegistrationBotCheck::token() }}">
<div aria-hidden="true" style="position: absolute; left: -10000px; top: auto; width: 1px; height: 1px; overflow: hidden;">
    <label for="{{ \App\Support\RegistrationBotCheck::HONEYPOT }}">Webseite (bitte leer lassen)</label>
    <input type="text" id="{{ \App\Support\RegistrationBotCheck::HONEYPOT }}" name="{{ \App\Support\RegistrationBotCheck::HONEYPOT }}" value="" tabindex="-1" autocomplete="off">
</div>
