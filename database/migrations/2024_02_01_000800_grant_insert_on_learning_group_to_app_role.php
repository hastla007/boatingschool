<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lerngruppen legen jetzt auch Schuladmins im Admin-Bereich an
 * (Admin\LearningGroupController). Statt dafür im Web-Request auf die
 * Owner-Verbindung (ohne RLS) zu wechseln, darf die App-Rolle Gruppen
 * einfügen: die Policy learning_group_isolation (WITH CHECK) lässt nur
 * Zeilen der aktuellen Schule zu, learning_group_owner_member_fk nur
 * Verwalter derselben Schule. Löschen bleibt der Owner-Verbindung
 * vorbehalten (Schulende).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement('GRANT INSERT ON learning_group TO '.AppRoleGrants::quotedRole());
        }
    }

    public function down(): void
    {
        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement('REVOKE INSERT ON learning_group FROM '.AppRoleGrants::quotedRole());
        }
    }
};
