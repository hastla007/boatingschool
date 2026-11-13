<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $table = 'platform_setting';

    const CREATED_AT = null;

    const UPDATED_AT = 'updated_at';

    protected $fillable = ['site_name', 'support_email', 'maintenance_mode', 'maintenance_message', 'imprint_template', 'privacy_template', 'operator_info', 'mail_templates', 'new_tenant_requires_team_two_factor'];

    protected function casts(): array
    {
        return ['maintenance_mode' => 'boolean', 'mail_templates' => 'array', 'new_tenant_requires_team_two_factor' => 'boolean'];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1]);
    }

    /**
     * Bug-Review B16: site_name wurde gespeichert, aber z. B. im Seitentitel
     * nie gelesen -- ein reines first() statt current(), damit das auch
     * unter der seit Sicherheitsreview M7 nur noch SELECT-berechtigten
     * App-Rolle funktioniert (current() würde dort bei fehlender Zeile per
     * firstOrCreate() crashen; in der Praxis seedet die Migration die Zeile
     * aber immer).
     */
    public static function siteName(): string
    {
        return static::query()->first()?->site_name ?: config('app.name');
    }

    /**
     * „2FA-Pflicht für neue Schulen“ (Standard an): Startwert von
     * tenant_branding.require_team_two_factor beim Anlegen einer Schule.
     */
    public static function newTenantRequiresTeamTwoFactor(): bool
    {
        return (bool) (static::query()->first()?->new_tenant_requires_team_two_factor ?? true);
    }
}
