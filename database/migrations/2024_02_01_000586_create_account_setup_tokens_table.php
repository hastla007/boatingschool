<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Review-Runde 9: Einladungs-Links (Passwort festlegen, 7 Tage gültig) in
 * einer eigenen Tabelle statt in password_reset_tokens -- sonst galt ein
 * 60-Minuten-Reset-Token mit „invite=1“ sieben Tage, und das tägliche
 * Aufräumen der Reset-Tokens löschte die Einladungen nach einer Stunde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_setup_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON account_setup_tokens TO '.AppRoleGrants::quotedRole());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_setup_tokens');
    }
};
