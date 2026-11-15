<?php

namespace App\Console\Commands;

use App\Support\BackupEncryption;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Entschlüsselt eine externe Sicherung (*.enc, siehe BackupEncryption) zum
 * Zurückspielen, z. B. database.dump.enc → database.dump. Braucht keine
 * Datenbank; der geheime Schlüssel kommt aus einer Datei oder wird abgefragt.
 */
class BackupDecrypt extends Command
{
    protected $signature = 'app:backup-decrypt
        {input : Verschlüsselte Datei (.enc)}
        {output? : Ziel (Standard: ohne .enc daneben)}
        {--key-file= : Datei mit dem geheimen Schlüssel (sonst Abfrage)}';

    protected $description = 'Entschlüsselt eine verschlüsselte externe Sicherung.';

    public function handle(): int
    {
        $input = (string) $this->argument('input');
        $output = (string) ($this->argument('output') ?: preg_replace('/'.preg_quote(BackupEncryption::EXTENSION, '/').'$/', '', $input));
        if ($output === $input || File::exists($output)) {
            $this->error("Ziel „{$output}“ gibt es schon (oder fehlt) -- bitte ein neues Ziel angeben.");

            return self::FAILURE;
        }

        $keyFile = $this->option('key-file');
        $secret = $keyFile ? (string) @file_get_contents($keyFile) : (string) $this->secret('Geheimer Schlüssel');

        try {
            BackupEncryption::decryptFile($input, $output, $secret);
        } catch (RuntimeException $e) {
            $this->error('Entschlüsseln fehlgeschlagen: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Entschlüsselt: {$output} (SHA-256 ".hash_file('sha256', $output).', mit manifest.json vergleichen)');

        return self::SUCCESS;
    }
}
