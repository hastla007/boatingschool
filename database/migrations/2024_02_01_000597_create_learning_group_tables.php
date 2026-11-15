<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lerngruppen (Gruppenlizenzen wie in der WP-App): eine Person verwaltet
 * eine feste Zahl Plätze und vergibt sie per Beitrittscode oder persönlicher
 * Einladung. Jede Gruppe gehört genau einer Schule:
 * - tenant_id + RLS je Schule auf beiden Tabellen,
 * - Mitglied und Verwalter müssen Mitglieder derselben Schule sein
 *   (zusammengesetzte Fremdschlüssel auf tenant_user). Verlässt jemand die
 *   Schule, verschwindet seine Mitgliedschaft; verlässt der Verwalter sie,
 *   bleibt die Gruppe ohne Verwalter bestehen (bezahlte Plätze laufen weiter).
 * Angelegt werden Gruppen nur auf der Owner-Verbindung (learning-groups:create).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_group', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('owner_user_id')->nullable();
            $table->string('name', 120);
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('seats');
            $table->jsonb('scopes');
            $table->timestampTz('valid_from')->useCurrent();
            $table->timestampTz('valid_until')->nullable();
            $table->string('join_code', 33)->unique();
            $table->string('source_type', 40)->default('manual');
            $table->string('source_reference', 160)->nullable();
            $table->timestampsTz();
            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'owner_user_id', 'created_at']);
        });
        DB::statement('ALTER TABLE learning_group ADD CONSTRAINT learning_group_owner_member_fk FOREIGN KEY (tenant_id, owner_user_id)
            REFERENCES tenant_user (tenant_id, user_id) ON DELETE SET NULL (owner_user_id)');
        DB::statement("ALTER TABLE learning_group ADD CONSTRAINT learning_group_status_check CHECK (status IN ('active','suspended','revoked'))");
        DB::statement('ALTER TABLE learning_group ADD CONSTRAINT learning_group_seats_check CHECK (seats > 0 AND seats <= 10000)');
        DB::statement('ALTER TABLE learning_group ADD CONSTRAINT learning_group_valid_range_check CHECK (valid_until IS NULL OR valid_until > valid_from)');
        DB::statement("ALTER TABLE learning_group ADD CONSTRAINT learning_group_scopes_check CHECK (jsonb_typeof(scopes) = 'array' AND jsonb_array_length(scopes) > 0)");
        DB::statement("ALTER TABLE learning_group ADD CONSTRAINT learning_group_join_code_check CHECK (join_code ~ '^g[0-9a-f]{32}$')");

        Schema::create('learning_group_member', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('group_id');
            $table->uuid('user_id')->nullable();
            $table->string('email', 320)->nullable();
            $table->string('status', 20)->default('invited');
            $table->string('invite_token', 33)->nullable()->unique();
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('joined_at')->nullable();
            $table->timestampsTz();
            // Deckt auch den Fremdschlüssel (group_id, tenant_id) ab.
            $table->index(['group_id', 'tenant_id', 'status']);
            $table->index(['tenant_id', 'user_id', 'status']);
        });
        DB::statement('ALTER TABLE learning_group_member ADD CONSTRAINT learning_group_member_group_fk FOREIGN KEY (group_id, tenant_id)
            REFERENCES learning_group (id, tenant_id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE learning_group_member ADD CONSTRAINT learning_group_member_tenant_user_fk FOREIGN KEY (tenant_id, user_id)
            REFERENCES tenant_user (tenant_id, user_id) ON DELETE CASCADE');
        DB::statement("ALTER TABLE learning_group_member ADD CONSTRAINT learning_group_member_status_check CHECK (status IN ('invited','active','removed','expired'))");
        // Aktive Mitglieder haben ein Konto, offene Einladungen eine Adresse.
        DB::statement("ALTER TABLE learning_group_member ADD CONSTRAINT learning_group_member_shape_check CHECK (
            (status <> 'active' OR user_id IS NOT NULL) AND (status <> 'invited' OR (email IS NOT NULL AND invite_token IS NOT NULL)))");
        DB::statement("CREATE UNIQUE INDEX learning_group_member_email_unique ON learning_group_member (group_id, lower(email)) WHERE email IS NOT NULL AND status IN ('invited','active')");
        // Kein doppelter Platz für dieselbe Person bei gleichzeitigem Beitritt.
        DB::statement("CREATE UNIQUE INDEX learning_group_member_user_unique ON learning_group_member (group_id, user_id) WHERE status = 'active'");

        foreach (['learning_group', 'learning_group_member'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY {$table}_isolation ON {$table}
                USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
                WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)");
        }

        if (AppRoleGrants::roleExistsOrWarn()) {
            $role = AppRoleGrants::quotedRole();
            // Anlegen/Löschen von Gruppen nur auf der Owner-Verbindung (Befehl, Schulende).
            DB::statement("GRANT SELECT, UPDATE ON learning_group TO {$role}");
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON learning_group_member TO {$role}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_group_member');
        Schema::dropIfExists('learning_group');
    }
};
