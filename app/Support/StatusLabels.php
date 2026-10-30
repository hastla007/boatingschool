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

    /**
     * Bug-Review (englische Optionen in Superadmin-Dropdowns): Kurstypen
     * wurden als rohe Werte ("full"/"addon"/"combo") angezeigt.
     *
     * @var array<string, string>
     */
    private const COURSE_TYPE = [
        'full' => 'Vollkurs',
        'combo' => 'Kombikurs',
        'addon' => 'Ergänzungskurs',
    ];

    /**
     * Bug-Review (englische Optionen in Superadmin-Dropdowns): "instructor"
     * und "staff" hießen beide "Mitarbeiter" -- in den Rollen-Dropdowns
     * standen dadurch zwei gleich lautende Einträge, obwohl nur
     * "instructor" Zugang zum Bootsschul-Adminbereich hat (siehe
     * AuthenticatedSessionController). Deshalb "Ausbilder" für instructor.
     *
     * @var array<string, string>
     */
    private const TENANT_ROLE = [
        'owner' => 'Inhaber',
        'admin' => 'Admin',
        'instructor' => 'Ausbilder',
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

    public static function courseType(string $type): string
    {
        return self::COURSE_TYPE[$type] ?? $type;
    }

    /**
     * Wert => deutsches Label für <select>-Optionen; der übermittelte Wert
     * bleibt der rohe Enumwert.
     *
     * @return array<string, string>
     */
    public static function tenantStatusOptions(): array
    {
        return self::TENANT_STATUS;
    }

    /** @return array<string, string> */
    public static function userStatusOptions(): array
    {
        return self::USER_STATUS;
    }

    /** @return array<string, string> */
    public static function courseStatusOptions(): array
    {
        return self::COURSE_STATUS;
    }

    /**
     * Ein Kurstyp ist in der DB frei (string, max 60) -- ein bereits
     * gespeicherter, hier unbekannter Wert bleibt als eigene Option
     * erhalten, statt beim nächsten Speichern stillschweigend durch
     * "Vollkurs" ersetzt zu werden.
     *
     * @return array<string, string>
     */
    public static function courseTypeOptions(?string $current = null): array
    {
        $options = self::COURSE_TYPE;
        if ($current !== null && $current !== '' && ! isset($options[$current])) {
            $options[$current] = $current;
        }

        return $options;
    }

    /**
     * Reihenfolge wie bisher in den Superadmin-Formularen.
     *
     * @return array<string, string>
     */
    public static function tenantRoleOptions(): array
    {
        $options = [];
        foreach (['learner', 'staff', 'instructor', 'admin', 'owner', 'support'] as $role) {
            $options[$role] = self::TENANT_ROLE[$role];
        }

        return $options;
    }
}
