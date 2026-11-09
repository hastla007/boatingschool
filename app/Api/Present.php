<?php

namespace App\Api;

use App\Models\MediaAsset;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\CanonicalUrl;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;

/** Gemeinsame Ausgabeformate der Mobile-API. */
class Present
{
    public static function time(?CarbonInterface $time): ?string
    {
        return $time?->copy()->utc()->toIso8601ZuluString();
    }

    /** Absolute URL zu einem Pfad der Plattform (z. B. /storage/...). */
    public static function url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return rtrim(CanonicalUrl::root(), '/').'/'.ltrim($path, '/');
    }

    public static function user(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
            'two_factor_enabled' => $user->hasTwoFactor(),
        ];
    }

    /**
     * Teilnehmer-Mitgliedschaften (Schulpersonal-Rollen gibt die App-API
     * nicht aus). „suspended“: Mitgliedschaft gesperrt oder Schule nicht
     * verfügbar.
     *
     * @return list<array<string, mixed>>
     */
    public static function memberships(User $user): array
    {
        $memberships = TenantUser::on('pgsql_admin')->withoutGlobalScopes()
            ->where('user_id', $user->id)->where('role', 'learner')->get();
        $tenants = Tenant::on('pgsql_admin')->whereIn('id', $memberships->pluck('tenant_id'))->get()->keyBy('id');
        $brandings = TenantBranding::on('pgsql_admin')->whereIn('tenant_id', $tenants->keys())->get()->keyBy('tenant_id');
        $logos = MediaAsset::on('pgsql_admin')->whereIn('id', $brandings->pluck('logo_asset_id')->filter())->get()->keyBy('id');

        return $memberships->map(function (TenantUser $membership) use ($tenants, $brandings, $logos) {
            $tenant = $tenants->get($membership->tenant_id);
            if (! $tenant) {
                return null;
            }
            $branding = $brandings->get($tenant->id);
            $available = $membership->status === 'active' && in_array($tenant->status, ['active', 'trial'], true);

            return [
                'tenant_slug' => $tenant->slug,
                'tenant_name' => $tenant->name,
                'status' => $available ? 'active' : 'suspended',
                'role' => 'learner',
                'branding' => [
                    'logo_url' => self::url($logos->get($branding?->logo_asset_id)?->storage_path),
                    'primary_color' => $branding?->primary_color,
                    'secondary_color' => $branding?->secondary_color,
                    'support_email' => $branding?->support_email && $branding->support_email_verified_at ? $branding->support_email : null,
                ],
                'legal' => [
                    'imprint_url' => CanonicalUrl::root($tenant).'/impressum',
                    'privacy_url' => CanonicalUrl::root($tenant).'/datenschutz',
                    // Review-Runde 13: Konto löschen und Datenrechte laufen im Web.
                    'account_url' => CanonicalUrl::root($tenant).'/profile',
                    'data_rights_url' => CanonicalUrl::root($tenant).'/meine-daten',
                ],
            ];
        })->filter()->values()->all();
    }

    /** URL einer Datei auf der öffentlichen Disk (z. B. Seekarten-Ausschnitte). */
    public static function publicFile(?string $path): ?string
    {
        return $path ? self::url(Storage::disk('public')->url($path)) : null;
    }

    /** Bild zu einer Frage/Aufgabe. */
    public static function image(?string $path, ?string $alt = null, ?string $mime = null): array
    {
        return [
            'kind' => 'image',
            'available' => true,
            'label' => $alt ?: 'Abbildung',
            'url' => self::url($path),
            'mime_type' => $mime,
            'placeholder_reason' => null,
        ];
    }

    /** Sichtbarer Platzhalter für fehlende Medien (kein abspielbarer Link). */
    public static function placeholder(string $label, string $reason = 'awaiting_asset'): array
    {
        return ['kind' => 'placeholder', 'available' => false, 'label' => $label, 'url' => null, 'mime_type' => null, 'placeholder_reason' => $reason];
    }
}
