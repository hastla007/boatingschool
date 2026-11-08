<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Review-Runde 12: E-Mail-Adressen werden inzwischen überall klein
 * geschrieben gespeichert, ältere Konten konnten aber noch Großbuchstaben
 * enthalten -- eine Einladung oder Registrierung mit derselben Adresse in
 * Kleinschreibung legte dann ein ZWEITES Konto an, und Login/Passwort-Reset
 * (case-insensitiv) landeten auf einem beliebigen der beiden.
 *
 * Bestandsadressen werden klein geschrieben; der bisherige Index auf
 * lower(email) wird eindeutig. Gibt es schon Dubletten (nur bei
 * Altbeständen denkbar), bricht die Migration mit einer Liste ab, damit
 * sie von Hand zusammengeführt werden -- automatisch Konten zu
 * verschmelzen wäre riskanter.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('app_user')->selectRaw('lower(email) as email, count(*) as n')
            ->groupByRaw('lower(email)')->havingRaw('count(*) > 1')->pluck('email');
        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Mehrere Konten mit derselben E-Mail-Adresse (Groß-/Kleinschreibung): '.$duplicates->implode(', ').' -- bitte vor der Migration zusammenführen.');
        }

        DB::statement('UPDATE app_user SET email = lower(email) WHERE email <> lower(email)');
        DB::statement('UPDATE app_user SET pending_email = lower(pending_email) WHERE pending_email IS NOT NULL AND pending_email <> lower(pending_email)');
        DB::statement('DROP INDEX IF EXISTS idx_app_user_lower_email');
        DB::statement('CREATE UNIQUE INDEX idx_app_user_lower_email ON app_user (lower(email))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_app_user_lower_email');
        DB::statement('CREATE INDEX idx_app_user_lower_email ON app_user (lower(email))');
    }
};
