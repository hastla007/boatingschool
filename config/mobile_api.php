<?php

/*
| Mobile-API v1 (Lernenden-App). Laufzeiten der Gerätesitzungen gemäß
| App-Konzept; vor dem Go-live anpassbar.
*/
return [
    // Access-Token: kurzlebig.
    'access_ttl_minutes' => (int) env('MOBILE_API_ACCESS_TTL_MINUTES', 15),
    // Refresh-Token: rotiert bei jeder Erneuerung; verfällt nach dieser
    // Inaktivität bzw. spätestens nach der Höchstdauer ab Anmeldung.
    'refresh_idle_days' => (int) env('MOBILE_API_REFRESH_IDLE_DAYS', 30),
    'refresh_max_days' => (int) env('MOBILE_API_REFRESH_MAX_DAYS', 90),
    // Gültigkeit einer 2FA-Abfrage nach richtigem Passwort.
    'two_factor_challenge_minutes' => 10,
    // Datenexport: Download-Link und Datei bleiben so lange erhalten.
    'export_ttl_minutes' => (int) env('MOBILE_API_EXPORT_TTL_MINUTES', 60),
    // Idempotenz-Schlüssel werden so lange aufbewahrt.
    'idempotency_hours' => 24,\n    // Einmalige Anmeldung für die Webansicht aus einer Mobile-App.\n    'web_session_enabled' => (bool) env('MOBILE_API_WEB_SESSION_ENABLED', true),\n    // Universal-Link-Pfad, den die App aus der Bestätigungsmail öffnet.\n    'deletion_confirmation_path' => env('MOBILE_API_DELETION_CONFIRMATION_PATH', '/app/konto-loeschen/'),
    // Mindestversion der App je Plattform (leer = keine Vorgabe). Ältere
    // Versionen bekommen 426 APP_UPDATE_REQUIRED (Header X-App-Version und
    // X-App-Platform); GET /api/v1/app/config nennt die Werte.
    'minimum_version' => [
        'ios' => env('MOBILE_API_MIN_VERSION_IOS'),
        'android' => env('MOBILE_API_MIN_VERSION_ANDROID'),
    ],
    'store_url' => [
        'ios' => env('MOBILE_API_STORE_URL_IOS'),
        'android' => env('MOBILE_API_STORE_URL_ANDROID'),
    ],
];
