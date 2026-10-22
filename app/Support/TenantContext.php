<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Hält den pro Request aufgelösten Tenant. Wird von TenantResolver gesetzt,
 * bevor irgendeine tenant-gebundene Query läuft.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        // Postgres' SET erlaubt keine Bind-Parameter; da die UUID aus unserem
        // eigenen DB-Primärschlüssel stammt, wird sie zusätzlich hart gegen
        // das UUID-Format geprüft, bevor sie interpoliert wird.
        if (! preg_match('/^[0-9a-f-]{36}$/i', $tenant->id)) {
            throw new \InvalidArgumentException('Ungültige Tenant-ID.');
        }

        // RLS-Grundlage: Der Webprozess verbindet sich als least-privilege
        // Rolle boatingschool_app; diese SET-Anweisung ist die einzige Quelle
        // für app.current_tenant_id, die die DB-Policies auswerten.
        //
        // Sicherheitsreview M5: SET LOCAL statt SET -- ein bloßes SET setzt
        // die GUC für die gesamte Verbindungs-SESSION, nicht nur die
        // aktuelle Transaktion. Bei wiederverwendeten Verbindungen
        // (Connection-Pooling/PgBouncer im Session-Modus, Octane) könnte ein
        // reines SET sonst über das Ende der aktuellen Transaktion hinaus
        // bestehen bleiben. SET LOCAL wird am Transaktionsende automatisch
        // zurückgesetzt -- dafür muss ResolveTenant::handle() den gesamten
        // Request in eine Transaktion auf dieser Verbindung einpacken, siehe
        // dort.
        DB::statement("SET LOCAL app.current_tenant_id = '{$tenant->id}'");
    }

    /**
     * Setzt den DB-Kontext auf die Nil-UUID statt RESET: Für benutzerdefinierte
     * Postgres-GUCs setzt RESET nach dem ersten SET auf der Verbindung nicht
     * "nie gesetzt" (current_setting(..., true) => NULL) zurück, sondern auf
     * einen leeren String -- der bricht an jeder Policy mit ::uuid-Cast. Die
     * Nil-UUID matcht garantiert keinen echten Tenant und ist immer castbar.
     */
    public function clear(): void
    {
        $this->tenant = null;
        DB::statement("SET LOCAL app.current_tenant_id = '00000000-0000-0000-0000-000000000000'");
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?string
    {
        return $this->tenant?->id;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }
}
