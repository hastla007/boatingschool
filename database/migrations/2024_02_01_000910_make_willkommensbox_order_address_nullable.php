<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Löschkonzept Nr. 17: nach Versand + Frist (DATA_RETENTION_WILLKOMMENSBOX_
 * ADDRESS_DAYS) entfernt app:prune-personal-data die Lieferadresse einer
 * Willkommensbox-Bestellung. Die Bestellung selbst bleibt (Versandstatistik,
 * „schon bestellt“ je Teilnehmer und Schule) -- dafür müssen die
 * Adressfelder leer sein dürfen. Neue Bestellungen verlangen die Adresse
 * weiterhin (Validierung im WillkommensboxController).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE willkommensbox_order
            ALTER COLUMN street DROP NOT NULL,
            ALTER COLUMN postal_code DROP NOT NULL,
            ALTER COLUMN city DROP NOT NULL,
            ALTER COLUMN country DROP NOT NULL');
    }

    public function down(): void
    {
        DB::table('willkommensbox_order')->whereNull('street')->update(['street' => '']);
        DB::table('willkommensbox_order')->whereNull('postal_code')->update(['postal_code' => '']);
        DB::table('willkommensbox_order')->whereNull('city')->update(['city' => '']);
        DB::table('willkommensbox_order')->whereNull('country')->update(['country' => '']);
        DB::statement('ALTER TABLE willkommensbox_order
            ALTER COLUMN street SET NOT NULL,
            ALTER COLUMN postal_code SET NOT NULL,
            ALTER COLUMN city SET NOT NULL,
            ALTER COLUMN country SET NOT NULL');
    }
};
