<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bug-Review B31: Mailversand (Passwort-Set-Link, Einladung, E-Mail-
 * Bestätigung) lief synchron im Request -- ein SMTP-Fehler ergab HTTP 500,
 * obwohl die eigentliche Schreiboperation (z. B. das neu angelegte Konto)
 * schon erfolgreich gespeichert war. Ein Versand, der NACH einer bereits
 * erfolgreichen Operation passiert, läuft deshalb nur noch best-effort:
 * ein Fehler wird geloggt, bricht den Request aber nicht ab.
 */
class BestEffortMail
{
    public static function send(callable $send, string $context): bool
    {
        try {
            $send();

            return true;
        } catch (Throwable $e) {
            Log::error("Mailversand fehlgeschlagen: {$context}", ['exception' => $e]);

            return false;
        }
    }
}
