<?php

namespace App\Support;

use App\Models\PlatformSetting;
use App\Models\Tenant;

/**
 * Review-Runde 12: aus welcher Schule kommt eine Mail? Der TenantContext
 * (Schulbereich), sonst die über den Pfad erkannte Schule auf schul-
 * unabhängigen Routen (Registrierung, Passwort vergessen, Bestätigungs-
 * mail erneut senden).
 */
class MailSchool
{
    public static function current(): ?Tenant
    {
        return app(TenantContext::class)->tenant() ?? (app()->bound('currentTenant') ? app('currentTenant') : null);
    }

    /** Unterschrift für die Markdown-Mail, Sonderzeichen entschärft (Schulname wie „Möwe *Nord*“). */
    public static function signature(): string
    {
        $name = self::current()?->name ?? PlatformSetting::siteName();

        return preg_replace('/([\\\\`*_{}\[\]#|>~])/', '\\\\$1', $name);
    }
}
