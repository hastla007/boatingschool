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
        // Bug-Review R3-7 (Folgefehler Sicherheitsreview M5): SET LOCAL
        // wirkte nur innerhalb der aktuellen Transaktion -- das zwang
        // ResolveTenant::handle() bisher dazu, den GESAMTEN restlichen
        // Request in einer Transaktion laufen zu lassen, nur damit die GUC
        // zuverlässig zurückgesetzt wird. Das hielt jedes im Request
        // erworbene DB-Lock bis zum Request-Ende (auch während synchronem
        // SMTP-Versand) und täuschte eine Atomarität des gesamten Requests
        // vor, die es nie gab (die meisten Fehlerpfade fangen ihre Exception
        // selbst, siehe BestEffortMail). SET (statt SET LOCAL) gilt für die
        // gesamte Verbindungs-SESSION; ResolveTenant::handle() setzt dafür
        // jetzt per app()->terminating()-Callback zuverlässig RESET am Ende
        // JEDES Requests, unabhängig vom Transaktionsstatus -- das erhält
        // die M5-Garantie (GUC bleibt nie über das Request-Ende hinaus auf
        // einer wiederverwendeten Verbindung bestehen, z. B. PgBouncer im
        // Session-Modus), ohne den ganzen Request in eine Transaktion zu
        // zwingen.
        DB::statement("SET app.current_tenant_id = '{$tenant->id}'");
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
        DB::statement("SET app.current_tenant_id = '00000000-0000-0000-0000-000000000000'");
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
