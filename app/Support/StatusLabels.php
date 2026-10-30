<?php

namespace App\Support;

/**
 * Bug-Review (Formular-Fehlermeldungen zeigen interne Feldnamen und
 * unübersetzte Enum-Werte): mehrere Admin-/Superadmin-Views gaben
 * Status-/Rollen-Enumwerte (z. B. "active", "learner") direkt aus der DB
 * unübersetzt aus, obwohl der restliche Admin-Bereich durchgehend Deutsch
 * ist. Zentrale Label-Zuordnung statt verstreuter Ternaries je View.
 */
class StatusLabels
{
    /** @var array<string, string> */
    private const TENANT_STATUS = [
        'trial' => 'Testphase',
        'active' => 'aktiv',
        'suspended' => 'gesperrt',
        'closed' => 'geschlossen',
    ];

    /** @var array<string, string> */
    private const USER_STATUS = [
        'invited' => 'eingeladen',
        'active' => 'aktiv',
        'suspended' => 'gesperrt',
        'closed' => 'geschlossen',
    ];

    /** @var array<string, string> */
    private const COURSE_STATUS = [
        'draft' => 'Entwurf',
        'published' => 'veröffentlicht',
        'archived' => 'archiviert',
    ];

    /** @var array<string, string> */
    private const ENTITLEMENT_STATUS = [
        'pending' => 'ausstehend',
        'active' => 'aktiv',
        'expired' => 'abgelaufen',
        'suspended' => 'gesperrt',
        'revoked' => 'widerrufen',
    ];

    /** @var array<string, string> */
    private const TENANT_ROLE = [
        'owner' => 'Inhaber',
        'admin' => 'Admin',
        'instructor' => 'Mitarbeiter',
        'staff' => 'Mitarbeiter',
        'learner' => 'Lerner',
        'support' => 'Support',
    ];

    public static function tenantStatus(string $status): string
    {
        return self::TENANT_STATUS[$status] ?? $status;
    }

    public static function userStatus(string $status): string
    {
        return self::USER_STATUS[$status] ?? $status;
    }

    public static function courseStatus(string $status): string
    {
        return self::COURSE_STATUS[$status] ?? $status;
    }

    public static function entitlementStatus(string $status): string
    {
        return self::ENTITLEMENT_STATUS[$status] ?? $status;
    }

    public static function tenantRole(string $role): string
    {
        return self::TENANT_ROLE[$role] ?? $role;
    }
}
