<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Einwilligungen und Mail-Einstellungen je Konto:
 * - age_confirmed_at: Bestätigung „mindestens 16 Jahre alt oder
 *   Erziehungsberechtigte einverstanden“ (Registrierung bzw. einmalig beim
 *   Passwort-Festlegen/Annehmen einer Einladung).
 * - speech_consent_at: Einwilligung in die Spracherkennung des Browsers
 *   (Audio geht an Google/Apple), widerrufbar im Profil.
 * - mail_preferences: abbestellte Mailarten (§ 7 UWG), z. B.
 *   {"learning_reminders": false}; fehlender Schlüssel = erwünscht.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_user', function (Blueprint $table) {
            if (! Schema::hasColumn('app_user', 'age_confirmed_at')) {
                $table->timestampTz('age_confirmed_at')->nullable();
            }
            if (! Schema::hasColumn('app_user', 'speech_consent_at')) {
                $table->timestampTz('speech_consent_at')->nullable();
            }
            if (! Schema::hasColumn('app_user', 'mail_preferences')) {
                $table->jsonb('mail_preferences')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('app_user', function (Blueprint $table) {
            foreach (['age_confirmed_at', 'speech_consent_at', 'mail_preferences'] as $column) {
                if (Schema::hasColumn('app_user', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
