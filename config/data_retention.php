<?php

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

];
