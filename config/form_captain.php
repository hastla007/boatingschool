<?php

/*
| Lead-Eingang für Form Captain (docs/integrations/form-captain-leads.md,
| Vertrag form-captain-lead/1). Getrennt von der Commerce-API: eigener
| Schlüssel, eigene Drosselung, keine Commerce-Rechte. Ohne
| FORM_CAPTAIN_LEAD_SECRET ist der Endpunkt abgeschaltet (alle Aufrufe 401).
*/
return [
    // Eingehend (WordPress-Connector -> App): Schlüssel-ID und gemeinsames
    // Geheimnis für die HMAC-Signatur. Für eine Rotation gilt vorübergehend
    // ein zweites Paar (…_NEXT). NIE das Commerce-Geheimnis wiederverwenden.
    'keys' => array_filter([
        env('FORM_CAPTAIN_LEAD_KEY_ID', 'fc-lead') => env('FORM_CAPTAIN_LEAD_SECRET'),
        env('FORM_CAPTAIN_LEAD_KEY_ID_NEXT', 'fc-lead-next') => env('FORM_CAPTAIN_LEAD_SECRET_NEXT'),
    ]),

    // Widerrufene Schlüssel-IDs (kommagetrennt): 403 key_revoked statt 401,
    // damit der Connector „Schlüssel widerrufen“ anzeigen kann.
    'revoked_key_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('FORM_CAPTAIN_LEAD_REVOKED_KEY_IDS', ''))))),

    // Erlaubte Websites (source.site_url, kommagetrennt, nur Schema+Host).
    // Leer = jede Website, die einen gültigen Schlüssel hat.
    'allowed_site_urls' => array_values(array_filter(array_map('trim', explode(',', (string) env('FORM_CAPTAIN_LEAD_SITE_URLS', ''))))),

    // Zulässige Uhrzeit-Abweichung (Sekunden) und Speicherdauer der Nonce.
    'timestamp_tolerance' => 300,
    'nonce_ttl' => 600,

    // Höchstgröße des Bodys (Bytes).
    'max_body_bytes' => 16384,

    // Drosselung (eigene Töpfe, nicht die der Commerce-API).
    'rate_limits' => [
        'per_key_minute' => 60,
        'per_key_day' => 1000,
        // Neue Leads je Absender-E-Mail und 24 Stunden; darüber 202 accepted_merged.
        'per_email_day' => 5,
    ],

    // Idempotenz-Schlüssel und Ergebnis mindestens so lange speichern (Tage).
    'idempotency_days' => 30,

    // Aufbewahrung der Leads: PLATZHALTER bis zur Rechtsprüfung (BS-06,
    // Vertrag A.8). Ohne FORM_CAPTAIN_LEAD_RETENTION_ENABLED löscht
    // form-captain:prune-leads nur abgelaufene Idempotenz-Einträge.
    'retention' => [
        'enabled' => env('FORM_CAPTAIN_LEAD_RETENTION_ENABLED', false),
        // Tage nach dem Eingang bzw. nach der letzten Statusänderung.
        'days' => (int) env('FORM_CAPTAIN_LEAD_RETENTION_DAYS', 180),
    ],
];
