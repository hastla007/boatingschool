<?php

namespace Tests\Feature;

use App\Models\ExamPaper;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Prüfungshistorie im Kombikurs zeigt rohe Bogen-Nr.):
 * ProgressController::show() baute das Label eines abgeschlossenen
 * Prüfungsversuchs bisher direkt aus $session->paper->paper_number --
 * bei einem Kombikurs (SBF-SEE-BIN) trägt ein gespiegelter Binnen-Bogen
 * aber einen Offset (paper_number 101 für den ersten Binnen-Bogen, siehe
 * ComboExamPaperSeeder), den exam/papers.blade.php bereits korrekt über
 * App\Support\ComboExamGroups::infoFor() in eine Teilkurs-Anzeigenummer
 * auflöst ("Bogen 1" unter der Überschrift "SBF Binnen"). Die Historie
 * zeigte stattdessen "Prüfungsbogen Nr. 101".
 */
class ComboExamHistoryDisplaysTranslatedPaperNumberTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_the_exam_history_shows_the_combo_sub_course_label_instead_of_the_raw_paper_number(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $combo = $this->existingCourse('SBF-SEE-BIN');
        $this->grantEntitlement($tenant, $learner, $combo);

        $binnenPaper = $this->onAdmin(
            fn () => ExamPaper::where('course_id', $combo->id)->where('paper_number', '>=', 100)->orderBy('paper_number')->firstOrFail()
        );
        $ruleSet = $this->onAdmin(fn () => ExamRuleSet::where('course_id', $combo->id)->firstOrFail());

        $this->onAdmin(fn () => ExamSession::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'course_id' => $combo->id,
            'rule_set_id' => $ruleSet->id,
            'paper_id' => $binnenPaper->id,
            'status' => 'evaluated',
            'started_at' => now(),
            'submitted_at' => now(),
            'score' => 0.8,
            'passed' => true,
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('progress.show', $combo));

        $response->assertOk();
        $response->assertSee('SBF Binnen – Prüfungsbogen Nr. 1');
        $response->assertDontSee('Prüfungsbogen Nr. '.$binnenPaper->paper_number);
    }
}
