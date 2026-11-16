<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow-Vertrag (docs/workflow/README.md, WF-CUST1-02, -05, -07):
 *
 * - workflow_event: eigene, dauerhafte Ausgangstabelle (Outbox) für
 *   Ereignisse an den BoatingSchool Workflow Connector. Wird in derselben
 *   Transaktion wie die fachliche Änderung geschrieben und erst nach dem
 *   Commit zugestellt. Die id ist die stabile Event-ID (aus Typ, Gegenstand
 *   und Diskriminator abgeleitet): eine Wiederholung desselben fachlichen
 *   Vorgangs legt kein zweites Ereignis an. Nutzdaten nur aus der Positiv-
 *   liste (App\Workflow\PayloadAllowlist), nie Codes, Adressen oder Namen.
 *   Getrennt von commerce_event (tenant.provision-Ausgang an den Shop).
 * - workflow_nonce: Wiedergabeschutz des Eingangs (Schlüssel-ID + Nonce
 *   einmal je Zeitfenster), dauerhaft statt nur im Cache.
 * - workflow_command: Idempotenz der Befehle (je Schlüssel-ID und
 *   Idempotenzschlüssel genau eine Ausführung, Ergebnis ohne Personendaten).
 *
 * Plattformtabellen ohne RLS: Zugriff über die Owner-Verbindung bzw. für
 * die App-Rolle nur INSERT auf workflow_event (Ereignisse aus Abläufen, die
 * auf der App-Verbindung laufen). Die Schule steht bewusst als tenant_ref,
 * nicht als tenant_id: sie ist nur Referenz (WF-CUST1-06), kein Fremd-
 * schlüssel und keine mandantengebundene Zeile unter RLS. Ein Ereignis
 * verhindert die Löschung einer Schule nicht.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_event', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 60);
            $table->unsignedSmallInteger('version');
            // tenant | platform (Ereignis noch ohne Schule, z. B. Registrierung)
            $table->string('scope', 10);
            $table->uuid('tenant_ref')->nullable();
            $table->string('subject_type', 30);
            $table->string('subject_id', 64);
            $table->string('correlation_id', 64);
            $table->jsonb('payload');
            $table->string('status', 10); // pending | sending | done | failed
            $table->integer('attempts')->default(0);
            // Nur ein kurzer Code (http_503, timeout, rejected:…), nie der Antworttext.
            $table->string('last_error', 120)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('available_at')->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['status', 'available_at']);
            $table->index('tenant_ref');
            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });

        Schema::create('workflow_nonce', function (Blueprint $table) {
            $table->string('key_id', 64);
            $table->string('nonce', 64);
            $table->timestampTz('expires_at');
            $table->primary(['key_id', 'nonce']);
            $table->index('expires_at');
        });

        Schema::create('workflow_command', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('key_id', 64);
            $table->string('idempotency_key', 128);
            $table->string('action', 60);
            $table->uuid('tenant_ref');
            $table->char('request_hash', 64);
            $table->string('status', 10); // running | done
            $table->jsonb('result')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('finished_at')->nullable();
            $table->unique(['key_id', 'idempotency_key']);
            $table->index(['tenant_ref', 'action', 'created_at']);
        });

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement('GRANT INSERT ON workflow_event TO '.AppRoleGrants::quotedRole());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_command');
        Schema::dropIfExists('workflow_nonce');
        Schema::dropIfExists('workflow_event');
    }
};
