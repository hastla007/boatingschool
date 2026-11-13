<?php

/*
|--------------------------------------------------------------------------
| Angaben für die Datenschutzerklärung der Schulen (App\Support\LegalPages)
|--------------------------------------------------------------------------
|
| Abschnitte zu Diensten, die nicht jede Installation nutzt, erscheinen nur,
| wenn sie hier (bzw. in der jeweiligen Dienst-Konfiguration) eingerichtet
| sind. Rechtliche Einordnung siehe docs/recht/.
|
*/
return [

    // Videohoster der Lernvideos. Ohne Namen entfällt der Abschnitt.
    'video_provider' => [
        'name' => env('PRIVACY_VIDEO_PROVIDER'),             // z. B. "Bunny.net (BunnyWay d.o.o.)"
        'country' => env('PRIVACY_VIDEO_PROVIDER_COUNTRY'),  // z. B. "Slowenien"
        'privacy_url' => env('PRIVACY_VIDEO_PROVIDER_URL'),
        // Sitz außerhalb von EU/EWR: Grundlage der Übermittlung (Art. 44 ff. DSGVO).
        'transfer_basis' => env('PRIVACY_VIDEO_PROVIDER_TRANSFER_BASIS', 'EU-Standardvertragsklauseln (Art. 46 Abs. 2 lit. c DSGVO)'),
    ],

    // Fehlertracking: Abschnitt nur mit gesetztem SENTRY_LARAVEL_DSN (config/sentry.php).
    'error_tracking' => [
        'name' => env('PRIVACY_ERROR_TRACKING_PROVIDER', 'Functional Software, Inc. (Sentry), 45 Fremont Street, 8th Floor, San Francisco, CA 94105, USA'),
        'retention_days' => (int) env('PRIVACY_ERROR_TRACKING_RETENTION_DAYS', 90),
    ],

    // Datensicherung (config/backup.php): Anbieter des externen Speichers
    // (BACKUP_DISK) und ob die Sicherungen dort verschlüsselt liegen.
    'backups' => [
        'provider' => env('PRIVACY_BACKUP_PROVIDER'),        // z. B. "Hetzner Online GmbH, Deutschland"
        // Standard: verschlüsselt, sobald app:backup einen Empfängerschlüssel hat
        // (BACKUP_ENCRYPT_RECIPIENT bzw. BACKUP_GPG_RECIPIENT).
        'encrypted' => (bool) env(
            'PRIVACY_BACKUPS_ENCRYPTED',
            filled(env('BACKUP_ENCRYPT_RECIPIENT')) || filled(env('BACKUP_GPG_RECIPIENT')),
        ),
    ],

    // Staaten der EU und des EWR: kein Hinweis auf eine Drittlandübermittlung.
    'eu_eea_countries' => [
        'Belgien', 'Bulgarien', 'Dänemark', 'Deutschland', 'Estland', 'Finnland', 'Frankreich', 'Griechenland',
        'Irland', 'Island', 'Italien', 'Kroatien', 'Lettland', 'Liechtenstein', 'Litauen', 'Luxemburg', 'Malta',
        'Niederlande', 'Norwegen', 'Österreich', 'Polen', 'Portugal', 'Rumänien', 'Schweden', 'Slowakei',
        'Slowenien', 'Spanien', 'Tschechien', 'Ungarn', 'Zypern', 'EU', 'EWR',
    ],
];
