<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Merkt die letzte Anmeldung eines Kontos (app_user.last_login_at) für die
 * Löschung inaktiver Lernender (app:prune-inactive-learners). Web-Anmeldung
 * (auch per „Angemeldet bleiben“) und App (Anmeldung und Token-Erneuerung).
 *
 * Eine neue Anmeldung macht eine verschickte Lösch-Vorwarnung hinfällig
 * (inactivity_warned_at zurücksetzen).
 *
 * Läuft auf der aktuellen Verbindung: RLS lässt das Update der eigenen
 * Zeile zu (Mitgliedschaft in der aktuellen Schule bzw. kein
 * Mandantenkontext). Ist die Zeile ausnahmsweise nicht sichtbar, ändert
 * sich nichts -- die Anmeldung selbst darf daran nie scheitern.
 */
class LoginActivity
{
    public static function record(string $userId): void
    {
        try {
            // Eigener Savepoint: ein Fehler hier bricht die umgebende
            // Request-Transaktion (ResolveTenant) nicht mit ab.
            DB::transaction(fn () => DB::table('app_user')->where('id', $userId)
                ->update(['last_login_at' => now(), 'inactivity_warned_at' => null]));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
