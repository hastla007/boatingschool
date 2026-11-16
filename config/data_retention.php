<?php

// Frist aus der .env: leer, nicht gesetzt oder <= 0 = aus (null). Lokale
// Variable statt Funktion -- die Datei wird u. U. mehrfach geladen.
$optionalPositive = static function (string $key): ?int {
    $value = env($key);

    return $value === null || $value === '' || (int) $value <= 0 ? null : (int) $value;
};

return [

    /*
    |--------------------------------------------------------------------------
    | Aufbewahrungsfristen (DSGVO-Review D3)
    |--------------------------------------------------------------------------
    |
    | Diese Werte sind bewusst konservative PLATZHALTER, keine rechtliche
    | Festlegung -- wie lange Audit-Log, Lern-Attempts und abgelaufene
    | Sessions tatsächlich aufbewahrt werden dürfen/müssen, ist eine
    | Entscheidung des Verantwortlichen (ggf. mit Rechtsberatung), u. a.
    | wegen möglicher Nachweispflichten für ausgestellte Bootsführerscheine.
    | app:prune-personal-data löscht nichts automatisch per Schedule, bis
    | diese Werte bewusst geprüft und in der eigenen .env gesetzt wurden
    | (siehe DATA_RETENTION_*_ENABLED unten).
    |
    */

    'audit_log' => [
        'enabled' => env('DATA_RETENTION_AUDIT_LOG_ENABLED', false),
        'months' => env('DATA_RETENTION_AUDIT_LOG_MONTHS', 24),
    ],

    'attempt' => [
        'enabled' => env('DATA_RETENTION_ATTEMPT_ENABLED', false),
        'months' => env('DATA_RETENTION_ATTEMPT_MONTHS', 36),
    ],

    // Audit ARC-4: audit_log/attempt werden in Blöcken dieser Größe gelöscht
    // (kurze Sperren und Transaktionen statt eines riesigen DELETE).
    'prune_chunk' => 5000,

    // Abgelaufene Sessions sind reine Betriebsdaten ohne eigenen Zweck nach
    // Ablauf von SESSION_LIFETIME -- hier reicht ein großzügiger, technischer
    // Puffer statt einer rechtlichen Abwägung, daher standardmäßig aktiv.
    'sessions' => [
        'enabled' => env('DATA_RETENTION_SESSIONS_ENABLED', true),
        'days' => env('DATA_RETENTION_SESSIONS_DAYS', 30),
    ],

    // Konten, die nie angenommen (status „invited“) bzw. deren E-Mail nie
    // bestätigt wurde: können sich selbst nicht löschen und hätten sonst
    // keine Frist.
    'unclaimed_accounts' => [
        'enabled' => env('DATA_RETENTION_UNCLAIMED_ACCOUNTS_ENABLED', true),
        'days' => env('DATA_RETENTION_UNCLAIMED_ACCOUNTS_DAYS', 90),
    ],

    // Lernende ohne Anmeldung und ohne Lernaktivität seit N Monaten und ohne
    // aktiven Kurszugang: Vorwarnung per Mail, 30 Tage später Löschung über
    // AccountErasureService (app:prune-inactive-learners, täglich).
    // null/leer = aus. Team-Mitglieder und Superadmins sind nie betroffen.
    'inactive_learners' => [
        'months' => env('DATA_RETENTION_INACTIVE_LEARNER_MONTHS') !== null && env('DATA_RETENTION_INACTIVE_LEARNER_MONTHS') !== ''
            ? (int) env('DATA_RETENTION_INACTIVE_LEARNER_MONTHS') : null,
        'warning_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Weitere Fristen (Löschkonzept Nr. 16, 17, 18, 20)
    |--------------------------------------------------------------------------
    |
    | Mechanismus vorhanden, Frist durch den Betreiber festzulegen: alle vier
    | stehen standardmäßig auf null (= aus), bis der Betreiber den Wert
    | bewusst in der .env setzt (siehe docs/recht/loeschkonzept.md). Alle
    | laufen im täglichen app:prune-personal-data.
    |
    */

    // Shop-Ereignisse (commerce_event): nur erledigte (status „done“, also
    // zugestellte Ausgangs-Ereignisse und protokollierte Aufrufe des Shops),
    // gemessen ab processed_at. Nie wartende, gerade gesendete oder
    // fehlgeschlagene Ausgangs-Ereignisse (sie brauchen noch einen Versand
    // bzw. „Erneut versuchen“) und nie Storno-Ereignisse („batch.voided“ --
    // sie sind der Idempotenz-Nachweis für wiederholte Storno-Aufrufe).
    'commerce_events' => [
        'days' => $optionalPositive('DATA_RETENTION_COMMERCE_EVENT_DAYS'),
    ],

    // Praxis-Anmeldungen (praxis_form_submission): N Monate nach dem letzten
    // Bezug (Änderung, fixierter Prüfungstermin, letzter Termin bzw. dessen
    // Rückmeldung) werden die Formularangaben (Name, Geburtsdatum, Anschrift
    // …) und die Freitext-Rückmeldungen zu Terminen entfernt sowie die
    // Kopien im Änderungsprotokoll geleert. Anmeldung, Kurs, Termine und
    // Zeitpunkte bleiben (Zählungen, „bereits angemeldet“).
    'praxis_submissions' => [
        'months' => $optionalPositive('DATA_RETENTION_PRAXIS_SUBMISSION_MONTHS'),
    ],

    // Willkommensbox (willkommensbox_order): N Tage nach dem Versand
    // (shipped_at) werden Lieferadresse, Hinweis und Sendungsnummer geleert
    // (auch im Änderungsprotokoll); die Bestellung bleibt. Nie vor dem Versand.
    'willkommensbox_addresses' => [
        'days' => $optionalPositive('DATA_RETENTION_WILLKOMMENSBOX_ADDRESS_DAYS'),
    ],

    // Gerätewiedererkennung (known_login_device): Geräte, die seit N Monaten
    // nicht mehr gesehen wurden (last_seen_at). Das zuletzt gesehene Gerät
    // je Konto bleibt, sonst gälte die nächste Anmeldung von einem fremden
    // Gerät wieder als „erstes Gerät“ ohne Hinweis-Mail.
    'known_login_devices' => [
        'months' => $optionalPositive('DATA_RETENTION_KNOWN_LOGIN_DEVICE_MONTHS'),
    ],

];
