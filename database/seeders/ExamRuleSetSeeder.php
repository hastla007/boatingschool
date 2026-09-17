<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\ExamBlueprint;
use App\Models\ExamRuleSet;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Platzhalter-Regelwerke für die Prüfungssimulation. Laut Umsetzungskonzept
 * ("Noch festzulegende Betriebsparameter") sind Fragenmix, Zeitlimit und
 * Bestehensgrenze je Katalog noch fachlich zu verifizieren -- Sprint 6 darf
 * laut Implementierungsplan nur mit freigegebenem Rule Set starten. Diese
 * Werte sind deshalb bewusst als "draft"/unverifiziert markiert; nur der
 * SBF-See-Datensatz wird zusätzlich als Demo-Verifizierung veröffentlicht,
 * damit die technische Prüfungssimulation end-to-end getestet werden kann.
 * Vor echtem Produktivbetrieb muss das fachlich geprüft werden.
 */
class ExamRuleSetSeeder extends Seeder
{
    public function run(): void
    {
        $reviewer = User::firstOrCreate(
            ['email' => 'content-admin@plattform.example'],
            ['display_name' => 'Plattform Content-Admin', 'password' => bcrypt('password'), 'status' => 'active']
        );

        $courses = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->get();

        foreach ($courses as $course) {
            $moduleCount = $course->modules()->count();
            $questionsPerModule = 10;

            $ruleSet = ExamRuleSet::updateOrCreate(
                ['course_id' => $course->id, 'version' => 'draft-2026-09'],
                [
                    'valid_from' => now()->toDateString(),
                    'time_limit_seconds' => 3600,
                    'passing_rule' => ['min_correct_ratio' => 0.75],
                    'status' => $course->code === 'SBF-SEE' ? 'published' : 'draft',
                    'verified_by' => $course->code === 'SBF-SEE' ? $reviewer->id : null,
                    'verified_at' => $course->code === 'SBF-SEE' ? now() : null,
                ]
            );

            foreach ($course->modules as $module) {
                ExamBlueprint::updateOrCreate(
                    ['rule_set_id' => $ruleSet->id, 'module_id' => $module->id],
                    ['question_count' => min($questionsPerModule, max(1, $module->questions()->count()))]
                );
            }
        }
    }
}
