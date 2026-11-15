<?php

/*
 * Datensicherung (app:backup, täglich per Scheduler): PostgreSQL-Dump
 * über die Owner-Verbindung plus alle Uploads (storage/app/public:
 * Logos, Bilder, PDFs) und die geschützten Dateien der privaten Disk
 * (Fragenbilder, Kartenausschnitte, Kursdateien) als ZIP.
 */
return [
    // Lokales Zielverzeichnis (je Lauf ein Unterordner mit Zeitstempel).
    'path' => env('BACKUP_PATH', storage_path('app/backups')),

    // So viele Tage bleiben Sicherungen lokal (und extern, siehe prune_remote).
    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),

    // Von den Sicherungen vor einem Deploy (app:backup --deploy) bleiben nur die letzten N.
    'keep_deploy' => (int) env('BACKUP_KEEP_DEPLOY', 3),

    // Optional zusätzlich auf einen externen Speicher kopieren, z. B. "s3"
    // (S3-kompatibel, Zugangsdaten über AWS_* in der .env). Leer = nur lokal.
    'disk' => env('BACKUP_DISK'),

    // Öffentlicher Schlüssel (php artisan app:backup-keygen): externe Kopien
    // werden damit vor dem Hochladen verschlüsselt. Den geheimen Schlüssel
    // nicht auf dem Server ablegen. Leer = unverschlüsselt (Go-live-Check warnt).
    'encryption_public_key' => env('BACKUP_ENCRYPTION_PUBLIC_KEY'),

    // Alte externe Sicherungen vom Server aus löschen. Aus (false), wenn der
    // Bucket per Lifecycle-Regel aufräumt und der Zugang nur schreiben darf
    // (Object Lock/Versionierung, siehe deploy/README.md).
    'prune_remote' => filter_var(env('BACKUP_PRUNE_REMOTE', true), FILTER_VALIDATE_BOOL),

    // Pfad zu pg_dump (Version muss zur Server-Version passen).
    'pg_dump' => env('BACKUP_PG_DUMP', 'pg_dump'),

    // Ab diesem Alter (Stunden) meldet app:check-production die letzte Sicherung als zu alt.
    'max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),

    // Optionaler Totmann-Ping nach jeder erfolgreichen täglichen Sicherung
    // (z. B. healthchecks.io); bleibt er aus, alarmiert der externe Dienst.
    'heartbeat_url' => env('OPS_BACKUP_HEARTBEAT_URL'),
];
