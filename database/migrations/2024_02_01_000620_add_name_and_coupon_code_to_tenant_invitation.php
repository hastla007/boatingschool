<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kein Konto-Orakel bei Einladungen: eine Einladung an eine Adresse mit
 * bestehendem Konto erscheint in der Teilnehmerliste genauso wie eine an
 * eine neue Adresse („Offene Einladungen“, mit dem von der Schule
 * eingegebenen Namen), und ein beim Einladen angegebener Code wird bei
 * bestehenden Konten vorgemerkt und beim Annehmen eingelöst
 * (InvitationAcceptController) statt die Antwort unterscheidbar zu machen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_invitation', function (Blueprint $table) {
            $table->string('name', 255)->nullable();
            $table->string('coupon_code', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_invitation', function (Blueprint $table) {
            $table->dropColumn(['name', 'coupon_code']);
        });
    }
};
