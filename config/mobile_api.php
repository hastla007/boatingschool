<?php

/*
| Mobile-API v1 (Lernenden-App). Laufzeiten der Gerätesitzungen gemäß
| App-Konzept; vor dem Go-live anpassbar.
*/
return [
    // Grundzugang zu Kursinhalten: 'entitlement' (Standard) = nur mit
    // gekauftem/zugewiesenem Zugang bzw. Zugangsregel (course_access_rule);
    // 'logged_in' = wie die WP-Einstellung „eingeloggt genügt“: JEDES
    // Mitglied der Schule bekommt Vollzugriff auf alle angebotenen Kurse.
    // Nur nach ausdrücklicher Entscheidung umstellen.
    'default_access' => env('MOBILE_API_DEFAULT_ACCESS', 'entitlement') === 'logged_in' ? 'logged_in' : 'entitlement',
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
    // Einmal-Anmeldung der eingebetteten Webansicht aus der App (POST /auth/web-session).
    'web_session_enabled' => (bool) env('MOBILE_API_WEB_SESSION_ENABLED', true),
    // Pfad auf der zentralen Domain, den die App als Universal-/App-Link
    // öffnet (Bestätigungslink der Kontolöschung aus der App).
    'deletion_confirmation_path' => env('MOBILE_API_DELETION_CONFIRMATION_PATH', '/app/konto-loeschen'),
    // Universal Links (iOS) und App Links (Android) für diesen Pfad: die zentrale
    // Domain liefert /.well-known/apple-app-site-association bzw.
    // /.well-known/assetlinks.json nur aus, wenn die Werte gesetzt sind
    // (kommagetrennt). Ohne installierte App öffnet der Link die Web-Bestätigung.
    'app_links' => [
        // App-IDs im Format <Team-ID>.<Bundle-ID>, z. B. ABCDE12345.de.example.app
        'ios_app_ids' => env('MOBILE_API_IOS_APP_IDS'),
        'android_package' => env('MOBILE_API_ANDROID_PACKAGE'),
        // SHA-256-Fingerprints des Signaturzertifikats (AA:BB:...)
        'android_sha256_fingerprints' => env('MOBILE_API_ANDROID_SHA256_FINGERPRINTS'),
    ],
];
