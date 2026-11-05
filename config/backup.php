<?php

/*
 * Datensicherung (app:backup, täglich per Scheduler): PostgreSQL-Dump
 * über die Owner-Verbindung plus alle Uploads (storage/app/public:
 * Logos, Bilder, Kartenausschnitte, PDFs) als ZIP.
 */
return [
    // Lokales Zielverzeichnis (je Lauf ein Unterordner mit Zeitstempel).
    'path' => env('BACKUP_PATH', storage_path('app/backups')),

    // So viele Tage bleiben Sicherungen lokal und auf dem externen Speicher.
    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),

    // Optional zusätzlich auf einen externen Speicher kopieren, z. B. "s3"
    // (S3-kompatibel, Zugangsdaten über AWS_* in der .env). Leer = nur lokal.
    'disk' => env('BACKUP_DISK'),

    // Pfad zu pg_dump (Version muss zur Server-Version passen).
    'pg_dump' => env('BACKUP_PG_DUMP', 'pg_dump'),

    // Ab diesem Alter (Stunden) meldet app:check-production die letzte Sicherung als zu alt.
    'max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),
];
