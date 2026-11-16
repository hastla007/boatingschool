<?php

/*
| Workflow-Vertrag (docs/workflow/README.md, WF-CUST1-02 bis -07): Ereignisse
| der App an den BoatingSchool Workflow Connector (WordPress-Plugin unter
| integrations/boatingschool-workflow-connector) und begrenzte Befehle von
| dort zurück. Getrennt von der Commerce-API: eigene Schlüssel, eigene
| Endpunkte (/api/integrations/v1/workflow), eigene Ausgangstabelle.
|
| Ohne WORKFLOW_ENABLED=true werden keine Ereignisse vorgemerkt; ohne
| WORKFLOW_SECRET ist der Eingang abgeschaltet (alle Aufrufe 401).
*/
return [
    'enabled' => (bool) env('WORKFLOW_ENABLED', false),

    // Version des Workflow-Vertrags (MAJOR.MINOR.PATCH). Der Connector prüft
    // sie im Handshake; eine andere Hauptversion gilt als inkompatibel.
    'contract_version' => '1.0.0',

    // Ausgang App -> Connector: Ereignis-Endpunkt des WordPress-Plugins.
    'endpoint' => env('WORKFLOW_CONNECTOR_URL'), // z. B. https://shop.example.de/wp-json/boatingschool-workflow/v1/events
    'outbound_key_id' => env('WORKFLOW_OUTBOUND_KEY_ID', 'app-workflow'),
    'outbound_secret' => env('WORKFLOW_OUTBOUND_SECRET'),

    // Eingang Connector -> App: Schlüssel-ID => Geheimnis und Rechte. Für einen
    // Schlüsselwechsel gilt vorübergehend ein zweites Paar (…_NEXT); danach
    // das alte Paar entfernen. Ein Schlüssel mit derselben ID oder demselben
    // Geheimnis wie ein Commerce-Schlüssel wird nie angenommen.
    // scopes: "tenant" (Befehle im Rahmen einer Schule). "platform" ist ein
    // eigener Scope; Version 1 kennt keinen Befehl dafür (alle abgelehnt).
    'keys' => array_filter([
        env('WORKFLOW_KEY_ID', 'workflow') => env('WORKFLOW_SECRET') ? ['secret' => env('WORKFLOW_SECRET'), 'scopes' => ['tenant']] : null,
        env('WORKFLOW_KEY_ID_NEXT', 'workflow-next') => env('WORKFLOW_SECRET_NEXT') ? ['secret' => env('WORKFLOW_SECRET_NEXT'), 'scopes' => ['tenant']] : null,
    ]),

    // Zulässige Uhrzeit-Abweichung einer signierten Anfrage (Sekunden).
    'timestamp_tolerance' => 300,

    // Größter angenommener Body (Bytes), geprüft vor jeder Auswertung.
    'max_body_bytes' => 65536,

    // Anfragen je Minute und Schlüssel (eigener Topf, nicht der Commerce-Topf).
    'rate_limit_per_minute' => (int) env('WORKFLOW_RATE_LIMIT', 60),

    // Ausgang: Versuche und Abstände (Minuten); danach „fehlgeschlagen“.
    // Erster Versuch sofort nach dem Commit.
    'backoff_minutes' => [1, 5, 15, 60, 180, 360, 720, 1440],

    // Zustellung direkt nach dem Commit: "defer" (nach der Antwort), "sync"
    // (sofort) oder "none" (nur der minütliche Scheduler-Lauf).
    'dispatch' => env('WORKFLOW_DISPATCH', 'defer'),

    // Zeitlimit eines Ausgangs-Aufrufs (Sekunden).
    'timeout_seconds' => 10,

    // Erledigte Ereignisse nach so vielen Tagen löschen (fehlgeschlagene bleiben).
    'retention_days' => 30,

    // Befehle (WF-CUST1-07): einzeln schaltbar, mit Tagesgrenze je Schule.
    'actions' => [
        'school_admin.notice' => ['enabled' => (bool) env('WORKFLOW_ACTION_NOTICE', true), 'daily_limit' => 20],
        'learning_group.invite' => ['enabled' => (bool) env('WORKFLOW_ACTION_GROUP_INVITE', false), 'daily_limit' => 50],
    ],
];
