<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit SEC-A3: Refresh-Tokens, die eine Wiederholung innerhalb der
 * Toleranz verdrängt hat. Wird eines davon später vorgelegt, hat jemand
 * anderes das vorherige Token benutzt -- die Sitzung wird widerrufen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('api_device_session', 'superseded_refresh_token_hashes')) {
            Schema::table('api_device_session', function (Blueprint $table) {
                $table->jsonb('superseded_refresh_token_hashes')->default('[]');
            });
        }
        DB::statement('CREATE INDEX IF NOT EXISTS api_device_session_superseded_refresh_gin ON api_device_session USING gin (superseded_refresh_token_hashes)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS api_device_session_superseded_refresh_gin');
        if (Schema::hasColumn('api_device_session', 'superseded_refresh_token_hashes')) {
            Schema::table('api_device_session', fn (Blueprint $table) => $table->dropColumn('superseded_refresh_token_hashes'));
        }
    }
};
