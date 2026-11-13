<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ob ein Produkt allein (als Produktcode) im Shop verkauft werden kann.
 * Als Zusatzprodukt zu einem Kurscode bleibt jedes aktive Produkt möglich.
 *
 * Nicht allein verkäuflich: „Lernplattform“ und „Lernplattform (inkl.
 * Willkommensbox)“ -- sie beschreiben den Zugang zu einem Kurs und ergeben
 * ohne Kurscode keinen Sinn (siehe 2024_02_01_000435). Fahrstunden und
 * Praxiseinheiten bleiben allein verkäuflich (z. B. nachgebuchte Praxis).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product', function (Blueprint $table) {
            $table->boolean('sellable_standalone')->default(true);
        });

        DB::table('product')->whereIn('code', ['LERNPLATTFORM', 'LERNPLATTFORM-WB'])->update(['sellable_standalone' => false]);
    }

    public function down(): void
    {
        Schema::table('product', function (Blueprint $table) {
            $table->dropColumn('sellable_standalone');
        });
    }
};
