<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bekannte Anmelde-Geräte von Team-Rollen und Superadmins (siehe
 * App\Support\NewLoginDeviceNotifier): je Konto ein Fingerabdruck aus
 * Browser-/Betriebssystem-Familie und gekürzter IP-Adresse (/24 bzw. /48),
 * nur als HMAC gespeichert -- weder die volle IP noch der User-Agent
 * liegen hier. Kontobezogen, nicht schulgebunden (kein tenant_id, kein
 * RLS); verschwindet per ON DELETE CASCADE mit dem Konto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('known_login_device', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('app_user')->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->timestampTz('first_seen_at')->useCurrent();
            $table->timestampTz('last_seen_at')->useCurrent();
            $table->unique(['user_id', 'fingerprint']);
        });

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE ON known_login_device TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('known_login_device');
    }
};
