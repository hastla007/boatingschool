<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Asymmetrische Verschlüsselung der Datensicherung (app:backup): mit
 * BACKUP_ENCRYPT_RECIPIENT (öffentlicher age-Schlüssel) und installiertem
 * age wird jede Datei per `age -r` verschlüsselt (.age), sonst mit
 * BACKUP_GPG_RECIPIENT per `gpg --encrypt --recipient` (.gpg). Auf dem
 * Server liegt nur der öffentliche Schlüssel -- wer den Server (oder den
 * S3-Zugang) übernimmt, kann alte Sicherungen nicht lesen.
 */
class BackupEncryption
{
    /**
     * 'age', 'gpg' oder null (unverschlüsselt). Ist ein Empfänger gesetzt,
     * das Werkzeug aber nicht installiert, bricht die Sicherung ab, statt
     * stillschweigend unverschlüsselt (womöglich nach extern) zu sichern.
     */
    public static function mode(): ?string
    {
        $age = config('backup.encrypt_recipient');
        $gpg = config('backup.gpg_recipient');

        if ($age && self::available((string) config('backup.age', 'age'))) {
            return 'age';
        }
        if ($gpg) {
            if (! self::available((string) config('backup.gpg', 'gpg'))) {
                throw new RuntimeException('BACKUP_GPG_RECIPIENT ist gesetzt, aber gpg ist nicht installiert.');
            }

            return 'gpg';
        }
        if ($age) {
            throw new RuntimeException('BACKUP_ENCRYPT_RECIPIENT ist gesetzt, aber age ist nicht installiert (apt install age) und BACKUP_GPG_RECIPIENT fehlt.');
        }

        return null;
    }

    public static function extension(string $mode): string
    {
        return $mode === 'age' ? '.age' : '.gpg';
    }

    /** @return list<string> */
    public static function encryptCommand(string $mode, string $input, string $output): array
    {
        if ($mode === 'age') {
            return [(string) config('backup.age', 'age'), '--encrypt', '--recipient', (string) config('backup.encrypt_recipient'), '--output', $output, $input];
        }

        // --trust-model always: der Schlüssel wurde bewusst importiert, ohne
        // Web-of-Trust-Signatur fragte gpg sonst interaktiv nach.
        return [(string) config('backup.gpg', 'gpg'), '--batch', '--yes', '--trust-model', 'always', '--encrypt',
            '--recipient', (string) config('backup.gpg_recipient'), '--output', $output, $input];
    }

    /**
     * Entschlüsseln für app:backup:verify. null, wenn auf diesem Rechner kein
     * privater Schlüssel eingerichtet ist (BACKUP_AGE_IDENTITY bzw.
     * BACKUP_GPG_DECRYPT=true).
     *
     * @return list<string>|null
     */
    public static function decryptCommand(string $mode, string $input, string $output): ?array
    {
        if ($mode === 'age') {
            $identity = config('backup.age_identity');

            return $identity ? [(string) config('backup.age', 'age'), '--decrypt', '--identity', (string) $identity, '--output', $output, $input] : null;
        }

        return config('backup.gpg_decrypt') ? [(string) config('backup.gpg', 'gpg'), '--batch', '--yes', '--decrypt', '--output', $output, $input] : null;
    }

    /** Verschlüsselungsart einer Sicherungsdatei anhand der Endung. */
    public static function modeOfFile(string $file): ?string
    {
        return match (true) {
            str_ends_with($file, '.age') => 'age',
            str_ends_with($file, '.gpg') => 'gpg',
            default => null,
        };
    }

    /** Ist das Programm vorhanden (absoluter Pfad oder im PATH)? */
    public static function available(string $binary): bool
    {
        if ($binary === '') {
            return false;
        }
        if (str_contains($binary, '/')) {
            return is_file($binary) && is_executable($binary);
        }

        return (new ExecutableFinder)->find($binary) !== null;
    }
}
