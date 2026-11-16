<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lead-Eingang für Form Captain (BS-06, docs/integrations/form-captain-leads.md):
 *
 * - form_captain_lead: ein unqualifizierter Interessent aus einem Formular
 *   der Website. Keinem Tenant zugeordnet, kein Benutzer, kein Shopkonto,
 *   kein Schuldatensatz. Status new | in_progress | done | rejected.
 *   retain_until = Ende der Aufbewahrung (form-captain:prune-leads).
 * - form_captain_lead_request: Idempotenz (Schlüssel, Body-Hash, Ergebnis)
 *   für mindestens 30 Tage -- auch nach dem Löschen des Leads, damit eine
 *   späte Wiederholung keinen gelöschten Lead neu anlegt. Keine Klartextdaten.
 *
 * Beide Tabellen sind nur über die Owner-Verbindung erreichbar (Superadmin,
 * signierter Endpunkt hinter admin-db): RLS an, keine Policy, kein GRANT an
 * die App-Rolle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_captain_lead', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('public_id', 40)->unique(); // L-<ULID>, die lead_id des Vertrags
            $table->string('status', 20)->default('new');
            $table->string('key_id', 64);
            $table->string('site_url', 200);
            $table->string('page_url', 500)->nullable();
            $table->unsignedBigInteger('form_id');
            $table->unsignedBigInteger('entry_id');
            $table->string('plugin_version', 20)->nullable();
            $table->string('purpose', 30)->nullable();
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_email', 190);
            $table->char('email_hash', 64);
            $table->string('contact_phone', 40)->nullable();
            $table->string('contact_organisation', 160)->nullable();
            $table->string('contact_role', 80)->nullable();
            $table->string('school_size', 10)->nullable();
            $table->text('message')->nullable();
            $table->timestampTz('consent_given_at');
            $table->unsignedInteger('consent_text_revision');
            $table->char('consent_text_hash', 64)->nullable();
            $table->timestampTz('submitted_at');
            // Weitere Einsendungen derselben Adresse über der Tagesgrenze (202 accepted_merged).
            $table->unsignedInteger('merged_count')->default(0);
            $table->timestampTz('last_merged_at')->nullable();
            // Bearbeitung im Superadmin (nur intern).
            $table->text('notes')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->uuid('handled_by_user_id')->nullable();
            $table->foreign('handled_by_user_id')->references('id')->on('app_user')->nullOnDelete();
            $table->timestampTz('status_changed_at')->nullable();
            $table->timestampTz('retain_until');
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
            $table->index(['email_hash', 'created_at']);
            $table->index('retain_until');
            $table->index('handled_by_user_id');
        });

        Schema::create('form_captain_lead_request', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('key_id', 64);
            $table->string('idempotency_key', 191);
            $table->char('body_hash', 64);
            $table->string('lead_public_id', 40);
            $table->unsignedSmallInteger('http_status');
            $table->string('code', 40);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique('idempotency_key'); // auch über eine Schlüsselrotation hinweg
            $table->index('created_at');
        });

        foreach (['form_captain_lead', 'form_captain_lead_request'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('form_captain_lead_request');
        Schema::dropIfExists('form_captain_lead');
    }
};
