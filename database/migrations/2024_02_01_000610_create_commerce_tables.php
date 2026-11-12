<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anbindung des B2B-WooCommerce-Shops (docs/commerce/README.md):
 *
 * - commerce_shop_account: ein Kundenkonto des Shops, verknüpft mit einem
 *   App-Konto und -- erst nach B2B-Prüfung bzw. Verknüpfungscode -- mit
 *   einer Schule. Plattformweit (keine RLS), Zugriff über die Owner-
 *   Verbindung bzw. gefiltert nach tenant_id.
 * - commerce_link_code: kurzlebiger Einmalcode, mit dem ein Schuladmin sein
 *   Shopkonto an die Schule bindet (nur der SHA-256-Hash wird gespeichert).
 * - coupon_batch: genau ein Code-Batch je bezahlter Bestellposition
 *   (eindeutig je Shop, Bestellung und Position) -- die Codes landen im
 *   Codebestand der Schule und verlassen die Plattform nicht.
 * - commerce_event: Protokoll eingehender Aufrufe und Ausgang (Outbox) an
 *   den Shop, ohne Codewerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_shop_account', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('shop_id', 64);
            $table->string('shop_customer_id', 64)->nullable();
            $table->uuid('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('app_user')->nullOnDelete();
            $table->uuid('tenant_id')->nullable();
            $table->foreign('tenant_id')->references('id')->on('tenant')->nullOnDelete();
            // pending_review | awaiting_link | linked | rejected | revoked
            $table->string('status', 20);
            // B2B-Angaben aus dem Shop (dort führend), für die Prüfung.
            $table->string('email', 255);
            $table->string('contact_name', 255)->nullable();
            $table->string('company_name', 255)->nullable();
            $table->char('billing_country', 2)->nullable();
            $table->string('vat_id', 40)->nullable();
            $table->timestampTz('shop_data_updated_at')->nullable();
            // Provisionierung App -> Shop (Schule vom Superadmin angelegt).
            $table->string('provisioning_status', 20)->nullable(); // pending | done | failed
            $table->text('provisioning_error')->nullable();
            $table->uuid('reviewed_by_user_id')->nullable();
            $table->foreign('reviewed_by_user_id')->references('id')->on('app_user')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestampTz('linked_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['shop_id', 'shop_customer_id']);
            $table->index('user_id');
            $table->index('tenant_id');
            $table->index('status');
            $table->index('reviewed_by_user_id');
        });

        Schema::create('commerce_link_code', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('created_by_user_id');
            $table->foreign('created_by_user_id')->references('id')->on('app_user')->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->uuid('used_by_account_id')->nullable();
            $table->foreign('used_by_account_id')->references('id')->on('commerce_shop_account')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->index('tenant_id');
            $table->index('created_by_user_id');
            $table->index('used_by_account_id');
        });

        Schema::create('coupon_batch', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->string('kind', 20); // course | renewal | product
            $table->uuid('course_id')->nullable();
            $table->foreign('course_id')->references('id')->on('course_definition')->nullOnDelete();
            $table->uuid('product_id')->nullable();
            $table->foreign('product_id')->references('id')->on('product')->nullOnDelete();
            $table->jsonb('option_product_ids')->nullable();
            $table->integer('quantity');
            $table->string('source', 20)->default('woocommerce');
            $table->string('shop_id', 64);
            $table->string('order_id', 64);
            $table->string('order_line_id', 64);
            $table->string('order_number', 64)->nullable();
            $table->uuid('shop_account_id')->nullable();
            $table->foreign('shop_account_id')->references('id')->on('commerce_shop_account')->nullOnDelete();
            $table->char('request_fingerprint', 64);
            $table->string('status', 20)->default('delivered'); // delivered | partially_voided | voided
            $table->string('void_reason', 500)->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['shop_id', 'order_id', 'order_line_id']);
            $table->index('tenant_id');
            $table->index('course_id');
            $table->index('product_id');
            $table->index('shop_account_id');
        });

        Schema::table('coupon', function (Blueprint $table) {
            $table->uuid('batch_id')->nullable();
            $table->foreign('batch_id')->references('id')->on('coupon_batch')->nullOnDelete();
            $table->index('batch_id');
        });

        Schema::create('commerce_event', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('direction', 3); // in | out
            $table->string('type', 60);
            $table->uuid('shop_account_id')->nullable();
            $table->foreign('shop_account_id')->references('id')->on('commerce_shop_account')->nullOnDelete();
            $table->uuid('tenant_id')->nullable();
            $table->foreign('tenant_id')->references('id')->on('tenant')->nullOnDelete();
            $table->string('correlation_id', 64)->nullable();
            // Nie Codewerte -- nur IDs, Mengen, Status.
            $table->jsonb('payload')->nullable();
            $table->string('status', 10); // pending | done | failed
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('available_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('processed_at')->nullable();
            $table->index(['direction', 'status', 'available_at']);
            $table->index('shop_account_id');
            $table->index('tenant_id');
            $table->index('created_at');
        });

        // Schuladmins sehen Batches, Verknüpfungscodes und Shopkonten nur ihrer
        // Schule (Commerce-API, Superadmin und Ausgang laufen auf der Owner-Verbindung).
        foreach (['coupon_batch', 'commerce_link_code', 'commerce_shop_account'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY {$table}_isolation ON {$table}
                USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
                WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)");
        }

        if (AppRoleGrants::roleExistsOrWarn()) {
            $role = AppRoleGrants::quotedRole();
            DB::statement("GRANT SELECT, INSERT, UPDATE ON commerce_link_code TO {$role}");
            DB::statement("GRANT SELECT ON coupon_batch TO {$role}");
            // Die Schule sieht ihre verknüpften Shopkonten und kann eine Verknüpfung
            // lösen -- ändern darf sie dabei nur den Status.
            DB::statement("GRANT SELECT ON commerce_shop_account TO {$role}");
            DB::statement("GRANT UPDATE (status, revoked_at, updated_at) ON commerce_shop_account TO {$role}");
            DB::statement("GRANT INSERT ON commerce_event TO {$role}");
        }
    }

    public function down(): void
    {
        Schema::table('coupon', function (Blueprint $table) {
            $table->dropForeign(['batch_id']);
            $table->dropColumn('batch_id');
        });
        Schema::dropIfExists('commerce_event');
        Schema::dropIfExists('coupon_batch');
        Schema::dropIfExists('commerce_link_code');
        Schema::dropIfExists('commerce_shop_account');
    }
};
