<?php

namespace App\Console\Commands;

use App\Support\BackupEncryption;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Schlüsselpaar für die Verschlüsselung der externen Sicherungen
 * (BackupEncryption). Am besten auf einem Arbeitsrechner statt auf dem
 * Server ausführen: der geheime Schlüssel gehört in den Passwortmanager.
 */
class BackupKeygen extends Command
{
    protected $signature = 'app:backup-keygen {--secret-key-file= : Geheimen Schlüssel in diese Datei schreiben (Rechte 0600) statt ihn anzuzeigen}';

    protected $description = 'Erzeugt das Schlüsselpaar für verschlüsselte externe Sicherungen.';

    public function handle(): int
    {
        $pair = BackupEncryption::generateKeyPair();

        if ($file = $this->option('secret-key-file')) {
            if (File::exists($file)) {
                $this->error("„{$file}“ gibt es schon -- nicht überschrieben.");

                return self::FAILURE;
            }
            File::put($file, $pair['secret']."\n");
            chmod($file, 0600);
            $this->info("Geheimer Schlüssel gespeichert: {$file}");
        } else {
            $this->line('Geheimer Schlüssel (sicher aufbewahren, NICHT auf den Server):');
            $this->line($pair['secret']);
        }

        $this->newLine();
        $this->line('In die .env des Servers eintragen:');
        $this->line('BACKUP_ENCRYPTION_PUBLIC_KEY='.$pair['public']);
        $this->newLine();
        $this->line('Prüfen: php artisan app:backup-decrypt <Datei>.enc <Ziel> --key-file=<geheimer Schlüssel>');

        return self::SUCCESS;
    }
}
