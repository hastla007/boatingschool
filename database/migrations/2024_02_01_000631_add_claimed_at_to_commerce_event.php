<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ausgang an den Shop (Outbox): ein Ereignis wird erst beansprucht
 * (status „sending“, claimed_at) und dann ohne offene Transaktion gesendet.
 * Bleibt es zu lange in „sending“ (Prozess abgebrochen), holt
 * Outbox::process() es zurück.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_event', function (Blueprint $table) {
            $table->timestampTz('claimed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('commerce_event', function (Blueprint $table) {
            $table->dropColumn('claimed_at');
        });
    }
};
