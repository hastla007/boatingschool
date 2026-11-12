<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_faq_category', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('source_key', 191)->unique();
            $table->string('course_code', 100);
            $table->uuid('parent_id')->nullable();
            $table->string('name', 191);
            $table->string('slug', 191);
            $table->integer('sort_order')->default(0);
            $table->unique(['course_code', 'slug']);
            $table->index(['course_code', 'parent_id', 'sort_order']);
        });
        // Create the table and its primary key before adding the self-reference.
        Schema::table('customer_faq_category', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('customer_faq_category')->cascadeOnDelete();
        });
        Schema::create('customer_faq', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('source_key', 191)->unique();
            $table->string('question', 500);
            $table->text('answer');
            $table->string('status', 16)->default('draft');
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
            $table->index(['status', 'sort_order']);
        });
        Schema::create('customer_faq_category_item', function (Blueprint $table) {
            $table->uuid('category_id');
            $table->foreign('category_id')->references('id')->on('customer_faq_category')->cascadeOnDelete();
            $table->uuid('faq_id');
            $table->foreign('faq_id')->references('id')->on('customer_faq')->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            $table->primary(['category_id', 'faq_id']);
        });
        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON customer_faq_category, customer_faq, customer_faq_category_item TO '.AppRoleGrants::quotedRole());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_faq_category_item');
        Schema::dropIfExists('customer_faq');
        Schema::dropIfExists('customer_faq_category');
    }
};
