<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bug-Review Betrieb B4: GRANT/REVOKE-Anweisungen in den Migrationen prüften
 * bislang per `rolname = 'boatingschool_app'` gegen einen in jeder einzelnen
 * Migration fest eingetragenen Rollennamen -- unabhängig davon, als welche
 * Rolle sich die Anwendung laut config('database.connections.pgsql.username')
 * (DB_USERNAME) tatsächlich mit Postgres verbindet. Weicht die tatsächlich
 * konfigurierte Rolle vom hartcodierten Namen ab (abweichende
 * Namenskonvention, Tippfehler bei der Rollenanlage laut README, ...), griff
 * die Prüfung nie, und sämtliche GRANTs für die betroffene(n) Tabelle(n)
 * wurden kommentarlos übersprungen -- ohne Fehler, ohne Log-Eintrag, ohne
 * jeden Hinweis im Migrationslauf. Das einzige Symptom war anschließend
 * "permission denied for relation ..." bei praktisch jedem echten Request,
 * weit nach dem (scheinbar erfolgreichen) `php artisan migrate`.
 *
 * role() liest die tatsächlich konfigurierte Rolle statt sie zu wiederholen,
 * roleExistsOrWarn() protokolliert eine fehlende Rolle sichtbar (STDERR und
 * Log), statt sie stillschweigend zu übergehen -- das bislang bewusst
 * tolerierte Szenario "migrate:fresh vor der Rollen-Provisionierung" (siehe
 * 2024_02_01_000457) bleibt dabei unverändert möglich, fällt danach aber
 * sofort auf.
 */
class AppRoleGrants
{
    public static function role(): string
    {
        return (string) config('database.connections.pgsql.username');
    }

    public static function roleExistsOrWarn(): bool
    {
        if (DB::selectOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [static::role()])) {
            return true;
        }

        $message = sprintf(
            "Migration: App-Rolle '%s' (config('database.connections.pgsql.username'), siehe DB_USERNAME) existiert nicht -- GRANT/REVOKE wird übersprungen. Rolle gemäß README anlegen und die betroffenen Rechte danach erneut nachziehen.",
            static::role()
        );

        fwrite(STDERR, '  WARNUNG: '.$message.PHP_EOL);
        Log::warning($message);

        return false;
    }
}
