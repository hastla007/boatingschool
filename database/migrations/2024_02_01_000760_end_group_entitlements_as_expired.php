<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * QA-L-2: Das Ende einer Gruppenmitgliedschaft (Entfernen, Gruppe beendet)
 * setzte Gruppenzugänge auf "revoked" -- Einlösen und Freischalten werten
 * das als bewusste Sperre (Betrug/Rückbuchung) und lehnten danach jeden
 * neuen Code ab. Gruppenzugänge enden jetzt als "expired" mit Enddatum
 * (LearningGroupService); bestehende Zeilen werden umgestellt. Bisher
 * widerrief nur das Gruppenende Gruppenzugänge.
 */
return new class extends Migration
{
    public function up(): void
    {
        // LEAST ignoriert NULL: unbefristete Zeilen enden zum Widerrufszeitpunkt
        // (mindestens 1 s nach valid_from, siehe entitlement_valid_range_check).
        DB::statement("UPDATE entitlement SET status = 'expired',
                valid_until = LEAST(valid_until, GREATEST(COALESCE(updated_at, now()), valid_from + interval '1 second')),
                updated_at = now()
            WHERE source_type = 'group' AND status = 'revoked'");
    }

    public function down(): void
    {
        // Nicht umkehrbar: beendete Gruppenzugänge bleiben abgelaufen.
    }
};
