<?php

namespace App\Support;

use App\Models\Tenant;
use DateTimeInterface;
use Illuminate\Support\Facades\URL;

/**
 * Sicherheitsreview S1 (Rest): signierte Links in E-Mails dürfen nicht vom
 * Host des auslösenden Requests abhängen. Über die Custom Domain einer
 * Bootsschule ausgelöst, landete der Link sonst auf einem Host, dessen DNS
 * ein Dritter kontrollieren kann (z. B. eine nicht mehr betriebene Custom
 * Domain). Links werden deshalb immer auf der zentralen Domain bzw. der
 * Subdomain der Bootsschule (<slug>.<zentrale Domain>) erzeugt -- beide
 * stehen unter Kontrolle der Plattform.
 */
class CanonicalUrl
{
    // Hinweis: das Schema (http/https) des fertigen Links übernimmt Laravel
    // vom aktuellen Request; die Angabe hier gilt nur ohne Request.
    public static function root(?Tenant $tenant = null): string
    {
        $scheme = app()->environment('production') ? 'https' : 'http';

        return $scheme.'://'.self::display($tenant);
    }

    /**
     * Adresse ohne Schema, z. B. "lernen.example.de/mueller" (Pfad-Modus,
     * Standard) oder "mueller.lernen.example.de" (TENANT_URL_MODE=subdomain).
     */
    public static function display(?Tenant $tenant = null): string
    {
        $host = config('app.central_domain');
        if (! $tenant) {
            return $host;
        }

        return config('app.tenant_url_mode') === 'subdomain' ? $tenant->slug.'.'.$host : $host.'/'.$tenant->slug;
    }

    /**
     * Signierte, ablaufende Route auf dem kanonischen Host. Die Signatur
     * umfasst den Host, deshalb wird die Wurzel-URL nur für diese eine
     * Erzeugung umgestellt.
     */
    public static function temporarySignedRoute(string $name, DateTimeInterface $expiration, array $parameters, ?Tenant $tenant = null): string
    {
        $generator = URL::getFacadeRoot();
        $generator->forceRootUrl(self::root($tenant));

        try {
            return $generator->temporarySignedRoute($name, $expiration, $parameters);
        } finally {
            $generator->forceRootUrl(null);
        }
    }
}
