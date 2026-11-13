<?php

/*
 * Datensicherung (app:backup, täglich per Scheduler): PostgreSQL-Dump
 * über die Owner-Verbindung plus alle Uploads (storage/app/public:
 * Logos, Bilder, Kartenausschnitte, PDFs) als ZIP. Prüfung per
 * Probe-Wiederherstellung: app:backup:verify (monatlich).
 */
return [
    // Lokales Zielverzeichnis (je Lauf ein Unterordner mit Zeitstempel).
    'path' => env('BACKUP_PATH', storage_path('app/backups')),

    // So viele Tage bleiben Sicherungen lokal (und extern nur mit remote_prune).
    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),

    // Optional zusätzlich auf einen externen Speicher kopieren, z. B. "s3"
    // (S3-kompatibel, Zugangsdaten über AWS_* in der .env). Leer = nur lokal.
    'disk' => env('BACKUP_DISK'),

    // Alte Sicherungen auch auf dem externen Speicher löschen? Standard: nein.
    // Wer mit denselben Zugangsdaten löschen darf, kann bei einem
    // kompromittierten Server auch alle externen Kopien löschen -- die
    // Aufbewahrung extern deshalb über Lifecycle-Regeln des Buckets (mit
    // Versionierung/Object Lock) steuern, siehe deploy/README.md.
    'remote_prune' => (bool) env('BACKUP_REMOTE_PRUNE', false),

    // Asymmetrische Verschlüsselung (empfohlen bei BACKUP_DISK): Dump und
    // Uploads werden mit dem ÖFFENTLICHEN Schlüssel verschlüsselt (.age bzw.
    // .gpg), der private Schlüssel liegt nicht auf dem Server.
    // Vorrang hat age (age-encryption.org, Empfänger "age1..."), sonst GnuPG.
    'encrypt_recipient' => env('BACKUP_ENCRYPT_RECIPIENT'),
    'age' => env('BACKUP_AGE', 'age'),
    'gpg_recipient' => env('BACKUP_GPG_RECIPIENT'),
    'gpg' => env('BACKUP_GPG', 'gpg'),

    // Nur für app:backup:verify bei verschlüsselten Sicherungen: age-
    // Identitätsdatei (privater Schlüssel) bzw. GnuPG mit privatem Schlüssel
    // im Schlüsselbund. Ohne: nur Prüfsummen, keine Probe-Wiederherstellung.
    'age_identity' => env('BACKUP_AGE_IDENTITY'),
    'gpg_decrypt' => (bool) env('BACKUP_GPG_DECRYPT', false),

    // Pfad zu pg_dump bzw. pg_restore (Version muss zur Server-Version passen).
    'pg_dump' => env('BACKUP_PG_DUMP', 'pg_dump'),
    'pg_restore' => env('BACKUP_PG_RESTORE', 'pg_restore'),

    // app:backup:verify: diese Tabellen müssen in der Probe-Wiederherstellung Zeilen haben.
    'verify_tables' => array_values(array_filter(array_map('trim', explode(',', (string) env('BACKUP_VERIFY_TABLES', 'migrations,tenant,app_user,tenant_user,content_question'))))),

    // Ab diesem Alter (Stunden) meldet app:check-production die letzte Sicherung als zu alt.
    'max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),
];
