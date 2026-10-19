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
 * Fragenmix dieses Platzhalter-Regelwerks. ADD-BIN-SEGEL hat zusätzlich
 * eine eigene, von der Prüfungsordnung vorgegebene Bestehensgrenze (5
 * von 7 Fragen) und Bearbeitungszeit (15 Minuten) statt der generischen
 * Standardwerte. SRC hat eine eigene Bestehensgrenze (19 von 24 Fragen)
 * und Bearbeitungszeit (30 Minuten). Für alle anderen Kurse muss
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
        $verifiedCourses = ['SBF-SEE', 'SBF-BIN-MOTOR', 'SBF-SEE-BIN', 'ADD-SEE-TO-BIN', 'ADD-BIN-TO-SEE', 'SBF-BIN-SEGEL', 'ADD-BIN-SEGEL', 'SRC'];
        $questionCountOverrides = ['ADD-SEE-TO-BIN' => 23, 'ADD-BIN-TO-SEE' => 23];

        // ADD-BIN-SEGEL ("Ergänzung Binnen Segeln"): nur noch die 7
        // spezifischen Segelfragen je Bogen (kein allgemeiner Teil, keine
        // Basisfragen, da bei vorhandenem SBF Binnen bereits geprüft) --
        // mit eigener, verkürzter Bestehensgrenze (5 von 7) und
        // Bearbeitungszeit (15 Minuten) laut Prüfungsordnung.
        //
        // SRC ("SRC Vollkurs"): 19 von 24 Fragen müssen richtig
        // beantwortet werden (max. 5 Fehler), Bearbeitungszeit 30
        // Minuten.
        $timeLimitOverrides = ['ADD-BIN-SEGEL' => 900, 'SRC' => 1800];
        $passingRuleOverrides = ['ADD-BIN-SEGEL' => ['min_correct_ratio' => 5 / 7], 'SRC' => ['min_correct_ratio' => 19 / 24]];

        foreach ($courses as $course) {
            $questionsPerModule = 10;
            $isVerified = in_array($course->code, $verifiedCourses, true);

            $ruleSet = ExamRuleSet::updateOrCreate(
                ['course_id' => $course->id, 'version' => 'draft-2026-09'],
                [
                    'valid_from' => now()->toDateString(),
                    'time_limit_seconds' => $timeLimitOverrides[$course->code] ?? 3600,
                    'passing_rule' => $passingRuleOverrides[$course->code] ?? ['min_correct_ratio' => 0.75],
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
