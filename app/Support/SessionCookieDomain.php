<?php

namespace App\Support;

/**
 * Bug-Review R3-1 (Folgefehler): reine Ableitungslogik für
 * config/session.php, als eigene Methode testbar ohne Config/Env neu
 * bootstrappen zu müssen. Siehe die Erklärung in config/session.php.
 */
class SessionCookieDomain
{
    public static function resolve(?string $sessionDomain, string $centralDomain): ?string
    {
        if ($sessionDomain !== null && $sessionDomain !== '') {
            return $sessionDomain;
        }

        if (in_array($centralDomain, ['localhost', '127.0.0.1'], true)) {
            return null;
        }

        return '.'.$centralDomain;
    }
}
