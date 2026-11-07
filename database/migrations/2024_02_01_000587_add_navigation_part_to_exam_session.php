<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Navigationsaufgabe als eigener Prüfungsteil der Simulation (SBF See):
 * amtlich gehört zur theoretischen Prüfung eine Navigationsaufgabe mit
 * eigenem Mindestwert (7 von 9 Punkten) -- zusätzlich zu Basis 5/7 und
 * See 18/23 (ELWIS-Bewertungstabelle).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_session', function (Blueprint $table) {
            $table->uuid('navigation_task_id')->nullable();
            $table->foreign('navigation_task_id')->references('id')->on('navigation_task')->nullOnDelete();
            $table->index('navigation_task_id');
            $table->timestampTz('navigation_submitted_at')->nullable();
            $table->unsignedSmallInteger('navigation_points')->nullable();
        });

        Schema::create('exam_session_navigation_answer', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('exam_session_id');
            $table->foreign('exam_session_id')->references('id')->on('exam_session')->cascadeOnDelete();
            $table->uuid('navigation_question_id');
            $table->foreign('navigation_question_id')->references('id')->on('navigation_question')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->text('answer')->nullable();
            $table->timestampTz('answered_at')->nullable();
            $table->boolean('self_assessed_known')->nullable();
            $table->boolean('correct')->nullable();
            $table->unique(['exam_session_id', 'navigation_question_id']);
            $table->index('tenant_id');
            $table->index('navigation_question_id');
        });

        DB::statement('ALTER TABLE exam_session_navigation_answer ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY exam_session_navigation_answer_isolation ON exam_session_navigation_answer
            USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
            WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)");

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON exam_session_navigation_answer TO '.AppRoleGrants::quotedRole());
        }

        // Amtliches Teilminimum der Navigationsaufgabe in die Regelwerke mit
        // Navigation übernehmen (statt des bisherigen bloßen Hinweises).
        $note = 'Zur amtlichen Prüfung gehört zusätzlich die Navigationsaufgabe (mindestens 7 von 9 Punkten); sie wird hier nicht automatisch bewertet.';
        foreach (DB::table('exam_rule_set')->join('course_definition', 'course_definition.id', '=', 'exam_rule_set.course_id')
            ->whereIn('course_definition.code', ['SBF-SEE', 'ADD-BIN-TO-SEE'])->get(['exam_rule_set.id', 'exam_rule_set.passing_rule']) as $row) {
            $rule = json_decode($row->passing_rule, true) ?? [];
            if (isset($rule['navigation'])) {
                continue;
            }
            $rule['navigation'] = ['min_points' => 7, 'of' => 9];
            if (($rule['note'] ?? null) === $note) {
                unset($rule['note']);
            }
            DB::table('exam_rule_set')->where('id', $row->id)->update(['passing_rule' => json_encode($rule)]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_session_navigation_answer');
        Schema::table('exam_session', function (Blueprint $table) {
            $table->dropForeign(['navigation_task_id']);
            $table->dropColumn(['navigation_task_id', 'navigation_submitted_at', 'navigation_points']);
        });
    }
};
