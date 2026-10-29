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
 * 15 (80%) übernommen, über ALLE 15 Fragen eines Bogens (siehe
 * ExamEvaluationService); Bearbeitungszeit 30 Minuten. Offene Fragen
 * (question_type=open_text) zählen dabei über ihre Selbsteinschätzung
 * mit in diese 15 ein (Bug-Review: ein früherer Stand schloss sie
 * komplett aus -- die amtlichen Bögen haben aber nur 9-10 Nicht-
 * open_text-Fragen, die dokumentierte 80%-Grenze wäre dadurch je Bogen
 * uneinheitlich zu "8 von 9"/"8 von 10" auf dieser Teilmenge geworden,
 * statt wie beabsichtigt für alle 15 Fragen zu gelten). Für alle
 * anderen Kurse muss Zeitlimit/Fragenmix/Bestehensgrenze vor echtem
 * Produktivbetrieb noch fachlich geprüft werden.
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

        // Bug-Review B5: SBF-SEE, SBF-BIN-MOTOR, SBF-SEE-BIN, ADD-SEE-TO-BIN,
        // ADD-BIN-TO-SEE und SBF-BIN-SEGEL standen hier bislang ebenfalls als
        // "verifiziert"/published -- das schaltete über
        // ExamRuleSet::isVerified() echte, zählende Prüfungsversuche
        // (ExamController::startPaper) für sie frei, obwohl ihr
        // passing_rule weiterhin nur den generischen 75%-Platzhalter unten
        // (Zeile "?? ['min_correct_ratio' => 0.75]") nutzte, statt einer
        // tatsächlich amtlichen, aus der Prüfungsordnung belegten
        // Bestehensgrenze. Nur Kurse mit einer EXPLIZIT dokumentierten,
        // amtlichen Bestehensgrenze (siehe $passingRuleOverrides unten)
        // gelten hier als verifiziert; alle anderen bleiben "draft", bis
        // jemand die echten Werte recherchiert und einträgt.
        $verifiedCourses = ['ADD-BIN-SEGEL', 'SRC', 'FKN'];
        $questionCountOverrides = ['ADD-SEE-TO-BIN' => 23, 'ADD-BIN-TO-SEE' => 23];

        // UBI ("UBI Vollkurs"): die Bestehensgrenze (17 von 22 Fragen, 60
        // Minuten) ist eine echte, belegte Zahl aus der Prüfungsordnung --
        // die Bogen-ZUSAMMENSETZUNG selbst ist aber vorläufig/nicht amtlich
        // (siehe UbiExamPaperSeeder), UBI bleibt deshalb bewusst NICHT in
        // $verifiedCourses, bis die echte amtliche Zusammenstellung vorliegt.

        // ADD-BIN-SEGEL ("Ergänzung Binnen → Segeln"): nur noch die 7
        // spezifischen Segelfragen je Bogen (kein allgemeiner Teil, keine
        // Basisfragen, da bei vorhandenem SBF Binnen bereits geprüft) --
        // mit eigener, verkürzter Bestehensgrenze (5 von 7) und
        // Bearbeitungszeit (15 Minuten) laut Prüfungsordnung.
        //
        // SRC ("SRC Vollkurs"): 19 von 24 Fragen müssen richtig
        // beantwortet werden (max. 5 Fehler), Bearbeitungszeit 30
        // Minuten.
        $timeLimitOverrides = ['ADD-BIN-SEGEL' => 900, 'SRC' => 1800, 'FKN' => 1800, 'UBI' => 3600];
        $passingRuleOverrides = ['ADD-BIN-SEGEL' => ['min_correct_ratio' => 5 / 7], 'SRC' => ['min_correct_ratio' => 19 / 24], 'FKN' => ['min_correct_ratio' => 24 / 30], 'UBI' => ['min_correct_ratio' => 17 / 22]];

        // Bug-Review (Hoch, Folgefehler von CourseSeeder): dieser Seeder
        // läuft in Produktion erneut, sobald ein neuer Kurs dazukommt --
        // updateOrCreate() überschrieb dabei bisher BEI JEDEM Lauf
        // time_limit_seconds/passing_rule/status/verified_by/verified_at
        // JEDES bereits existierenden Regelwerks mit genau diesen festen
        // Platzhaltern. Ein Regelwerk, dessen echte amtliche Werte zwischen-
        // zeitlich recherchiert und eingetragen wurden (siehe Klassen-
        // Docblock: "bis jemand die echten Werte ... einträgt"), wäre beim
        // nächsten Reseed lautlos wieder auf den Platzhalter zurückgefallen.
        // firstOrCreate() lässt ein bereits existierendes Regelwerk (und
        // seine ExamBlueprints) unberührt und erzeugt nur wirklich NEUE
        // Einträge mit den Startwerten.
        foreach ($courses as $course) {
            $questionsPerModule = 10;
            $isVerified = in_array($course->code, $verifiedCourses, true);

            $ruleSet = ExamRuleSet::firstOrCreate(
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

                ExamBlueprint::firstOrCreate(
                    ['rule_set_id' => $ruleSet->id, 'module_id' => $module->id],
                    ['question_count' => min($questionCount, max(1, $module->questions()->count()))]
                );
            }
        }
    }
}
