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
 * Werte sind deshalb bewusst als "draft"/unverifiziert markiert; nur
 * Kurse mit echten, amtlichen Prüfungsbögen (siehe exam_paper) werden
 * zusätzlich veröffentlicht, da ExamController::startPaper ein
 * verifiziertes Regelwerk als Startvoraussetzung verlangt, auch wenn der
 * eigentliche Versuch die festen Bogen-Fragen nutzt, nicht den
 * Fragenmix dieses Platzhalter-Regelwerks. Für alle anderen Kurse muss
 * Zeitlimit/Fragenmix/Bestehensgrenze vor echtem Produktivbetrieb noch
 * fachlich geprüft werden.
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

        // ADD-SEE-TO-BIN ("Ergänzung See → Binnen"): laut Prüfungsordnung
        // entfallen für Inhaber eines SBF See die 7 Basisfragen, sodass nur
        // die 23 Binnen-spezifischen Fragen geprüft werden. Dieses
        // Regelwerk ist deshalb -- wie SBF-SEE -- fachlich verifiziert und
        // nicht nur ein generischer Platzhalter.
        $verifiedCourses = ['SBF-SEE', 'SBF-BIN-MOTOR', 'SBF-SEE-BIN', 'ADD-SEE-TO-BIN', 'ADD-BIN-TO-SEE', 'SBF-BIN-SEGEL'];
        $questionCountOverrides = ['ADD-SEE-TO-BIN' => 23, 'ADD-BIN-TO-SEE' => 23];

        foreach ($courses as $course) {
            $questionsPerModule = 10;
            $isVerified = in_array($course->code, $verifiedCourses, true);

            $ruleSet = ExamRuleSet::updateOrCreate(
                ['course_id' => $course->id, 'version' => 'draft-2026-09'],
                [
                    'valid_from' => now()->toDateString(),
                    'time_limit_seconds' => 3600,
                    'passing_rule' => ['min_correct_ratio' => 0.75],
                    'status' => $isVerified ? 'published' : 'draft',
                    'verified_by' => $isVerified ? $reviewer->id : null,
                    'verified_at' => $isVerified ? now() : null,
                ]
            );

            foreach ($course->modules as $module) {
                $questionCount = $questionCountOverrides[$course->code] ?? $questionsPerModule;

                ExamBlueprint::updateOrCreate(
                    ['rule_set_id' => $ruleSet->id, 'module_id' => $module->id],
                    ['question_count' => min($questionCount, max(1, $module->questions()->count()))]
                );
            }
        }
    }
}
