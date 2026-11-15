<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit SEC-W-2: ob ein Konto JE eine bestätigte Adresse hatte. Der
 * Passwort-Reset entfernt eine 2FA nur bei Konten, die nie bestätigt
 * waren (Review-Runde 12) -- email_verified_at allein reichte dafür nicht,
 * weil eine Adressänderung durch den Support es auf null setzt.
 *
 * Ein Trigger setzt die Spalte beim ersten Bestätigen und hält sie danach
 * fest -- unabhängig davon, über welchen Weg email_verified_at gesetzt wird.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('app_user', 'email_ever_verified_at')) {
            Schema::table('app_user', fn (Blueprint $table) => $table->timestampTz('email_ever_verified_at')->nullable());
        }

        DB::statement('UPDATE app_user SET email_ever_verified_at = email_verified_at WHERE email_ever_verified_at IS NULL AND email_verified_at IS NOT NULL');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_user_keep_email_ever_verified() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND OLD.email_ever_verified_at IS NOT NULL THEN
                    NEW.email_ever_verified_at := OLD.email_ever_verified_at;
                ELSIF NEW.email_ever_verified_at IS NULL AND NEW.email_verified_at IS NOT NULL THEN
                    NEW.email_ever_verified_at := NEW.email_verified_at;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('DROP TRIGGER IF EXISTS app_user_keep_email_ever_verified ON app_user');
        DB::statement('CREATE TRIGGER app_user_keep_email_ever_verified BEFORE INSERT OR UPDATE ON app_user
            FOR EACH ROW EXECUTE FUNCTION app_user_keep_email_ever_verified()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS app_user_keep_email_ever_verified ON app_user');
        DB::statement('DROP FUNCTION IF EXISTS app_user_keep_email_ever_verified()');
        if (Schema::hasColumn('app_user', 'email_ever_verified_at')) {
            Schema::table('app_user', fn (Blueprint $table) => $table->dropColumn('email_ever_verified_at'));
        }
    }
};
