<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\ExamBlueprint;
use App\Models\ExamRuleSet;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
 * und Bearbeitungszeit (30 Minuten). FKN hat amtlich 24 von 30 Punkten
 * (2 Punkte je vollständig korrekter Antwort, 15 Fragen/Bogen) als
 * Bestehensgrenze -- diese Plattform bewertet Fragen binär (richtig/
 * falsch, keine Teilpunkte), daher rechnerisch gleichwertig als 12 von
 * 15 (80%) übernommen; Bearbeitungszeit 30 Minuten. Offene Fragen
 * (question_type=open_text) zählen dabei nicht zur Bestehensgrenze mit
 * (siehe ExamController::finish -- sie sind nicht automatisiert
 * auswertbar und dienen nur als Lernkarte), sodass sich die 80%-Grenze
 * effektiv nur auf die Multiple-Choice-Fragen je Bogen bezieht, nicht
 * auf alle 15 Fragen. Für alle anderen Kurse muss Zeitlimit/Fragenmix/
 * Bestehensgrenze vor echtem Produktivbetrieb noch fachlich geprüft
 * werden.
 */
class ExamRuleSetSeeder extends Seeder
{
    public function run(): void
    {
        // O3: dieses Konto dient nur der FK-Zuordnung (reviewed_by) und
        // wird nirgends für einen echten Login verwendet -- ein zufälliges
        // Passwort schließt aus, dass es trotzdem (z. B. per Credential-
        // Stuffing mit dem Standardwert "password") missbraucht werden kann.
        $reviewer = User::firstOrCreate(
            ['email' => 'content-admin@plattform.example'],
            ['display_name' => 'Plattform Content-Admin', 'password' => Hash::make(Str::random(40)), 'status' => 'active']
        );

        $courses = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->get();

        // ADD-SEE-TO-BIN ("Ergänzung See → Binnen"): laut Prüfungsordnung
        // entfallen für Inhaber eines SBF See die 7 Basisfragen, sodass nur
        // die 23 Binnen-spezifischen Fragen geprüft werden. Dieses
        // Regelwerk ist deshalb -- wie SBF-SEE -- fachlich verifiziert und
        // nicht nur ein generischer Platzhalter.
        $verifiedCourses = ['SBF-SEE', 'SBF-BIN-MOTOR', 'SBF-SEE-BIN', 'ADD-SEE-TO-BIN', 'ADD-BIN-TO-SEE', 'SBF-BIN-SEGEL', 'ADD-BIN-SEGEL', 'SRC', 'FKN'];
        $questionCountOverrides = ['ADD-SEE-TO-BIN' => 23, 'ADD-BIN-TO-SEE' => 23];

        // ADD-BIN-SEGEL ("Ergänzung Binnen → Segeln"): nur noch die 7
        // spezifischen Segelfragen je Bogen (kein allgemeiner Teil, keine
        // Basisfragen, da bei vorhandenem SBF Binnen bereits geprüft) --
        // mit eigener, verkürzter Bestehensgrenze (5 von 7) und
        // Bearbeitungszeit (15 Minuten) laut Prüfungsordnung.
        //
        // SRC ("SRC Vollkurs"): 19 von 24 Fragen müssen richtig
        // beantwortet werden (max. 5 Fehler), Bearbeitungszeit 30
        // Minuten.
        $timeLimitOverrides = ['ADD-BIN-SEGEL' => 900, 'SRC' => 1800, 'FKN' => 1800];
        $passingRuleOverrides = ['ADD-BIN-SEGEL' => ['min_correct_ratio' => 5 / 7], 'SRC' => ['min_correct_ratio' => 19 / 24], 'FKN' => ['min_correct_ratio' => 24 / 30]];

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
