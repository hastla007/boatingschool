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
    // Toleranz für die Wiederholung derselben Token-Erneuerung (verlorene
    // Antwort, parallele Anfragen): innerhalb dieser Sekunden gibt es ein
    // neues Paar statt der Beendigung der Sitzung.
    'refresh_reuse_grace_seconds' => (int) env('MOBILE_API_REFRESH_REUSE_GRACE_SECONDS', 60),
    // Gültigkeit einer 2FA-Abfrage nach richtigem Passwort.
    'two_factor_challenge_minutes' => 10,
    // Datenexport: Download-Link und Datei bleiben so lange erhalten.
    'export_ttl_minutes' => (int) env('MOBILE_API_EXPORT_TTL_MINUTES', 60),
    // Höchstens so viele ausgelieferte Fragen je Konto und Kalendertag
    // (Europe/Berlin) über Lernmodus und Prüfungsstart; darüber 429
    // DAILY_LIMIT_REACHED. Schutz gegen das Abgreifen des Fragenkatalogs.
    // 0 = keine Grenze.
    'daily_question_limit' => (int) env('MOBILE_API_DAILY_QUESTION_LIMIT', 2000),
    // Idempotenz-Schlüssel werden so lange aufbewahrt.
    'idempotency_hours' => 24,
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
