<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lead-Eingang, Vertrag 1.1 (Entscheidungen des Inhabers vom 16.11.2026,
 * docs/integrations/form-captain-leads.md): neue Zwecke (support, demo,
 * consulting, school_access, pricing) und für Support-Anfragen das gewählte
 * Produkt und die Kundenreferenz aus dem WooCommerce-Konto der Website.
 * Ältere Zweckwerte werden umgeschrieben.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_captain_lead', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_name', 200)->nullable();
            $table->string('product_sku', 100)->nullable();
            // WooCommerce-Kunden-ID und Konto-E-Mail der Website, kein Konto der Plattform.
            $table->unsignedBigInteger('customer_ref_id')->nullable();
            $table->string('customer_email', 190)->nullable();
            $table->index(['purpose', 'created_at']);
        });
        DB::table('form_captain_lead')->where('purpose', 'consultation')->update(['purpose' => 'consulting']);
        DB::table('form_captain_lead')->where('purpose', 'school_access_info')->update(['purpose' => 'school_access']);
    }

    public function down(): void
    {
        Schema::table('form_captain_lead', function (Blueprint $table) {
            $table->dropIndex(['purpose', 'created_at']);
            $table->dropColumn(['product_id', 'product_name', 'product_sku', 'customer_ref_id', 'customer_email']);
        });
    }
};
