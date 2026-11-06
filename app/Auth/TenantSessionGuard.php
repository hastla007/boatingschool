<?php

namespace App\Auth;

use Illuminate\Auth\SessionGuard;

/**
 * Session-Guard mit eigenem „Angemeldet bleiben“-Cookie je Schule im
 * Pfad-Modus (/<slug>/…). Die Sitzung hat dort bereits einen eigenen
 * Cookie-Namen und -Pfad (ResolveTenantPath); das Remember-Cookie hieß aber
 * überall gleich, sodass eine dauerhafte Anmeldung auf der zentralen Domain
 * (Pfad „/“) auch in jeder Schule unter /<slug>/ angemeldet hat.
 */
class TenantSessionGuard extends SessionGuard
{
    /** Gleicher Sitzungsschlüssel wie der Standard-Guard (ResolveTenant liest ihn direkt, bestehende Sitzungen bleiben gültig). */
    public function getName()
    {
        return 'login_'.$this->name.'_'.sha1(SessionGuard::class);
    }

    public function getRecallerName()
    {
        $base = 'remember_'.$this->name.'_'.sha1(SessionGuard::class);
        $path = (string) config('session.path', '/');

        return $path === '/' || $path === '' ? $base : $base.'_'.substr(sha1($path), 0, 12);
    }
}
