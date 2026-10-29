<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamRuleSet;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review B5: SBF-SEE, SBF-BIN-MOTOR, SBF-SEE-BIN, ADD-SEE-TO-BIN,
 * ADD-BIN-TO-SEE und SBF-BIN-SEGEL waren bislang als "verified"/
 * status=published markiert -- das schaltete über
 * ExamRuleSet::isVerified() echte, zählende Prüfungsversuche
 * (ExamController::startPaper) für sie frei, obwohl ihre passing_rule
 * weiterhin nur den generischen 75%-Platzhalter nutzte, statt einer
 * tatsächlich amtlichen, aus der Prüfungsordnung belegten
 * Bestehensgrenze. Nur Kurse mit einer explizit dokumentierten,
 * amtlichen Bestehensgrenze dürfen verifiziert sein.
 */
class ExamRuleSetAuthorityTest extends TestCase
{
    use InteractsWithTenants;

    private const UNVERIFIED_COURSE_CODES = [
        // SBF-BIN-SEGEL: die genannte Grenze (20/25 aus 4+14+7) passt
        // nicht zu den 37-Fragen-Bögen; SKN/ADD-FKN-TO-SKN: keine Bögen
        // und kein Zeitlimit. SBF-SEE-BIN: wie SRC-UBI unten, die Bögen
        // laufen nach ihren Teilkursen.
        'SBF-BIN-SEGEL', 'SBF-SEE-BIN', 'SKN', 'ADD-FKN-TO-SKN',
        // SRC-UBI: ein Regelwerk je Kurs kann die beiden amtlichen Grenzen
        // der Teilprüfungen (SRC 19/24, UBI 17/22) nicht gleichzeitig
        // abbilden -- die Bögen laufen nach dem Regelwerk ihres Teilkurses,
        // siehe SrcUbiComboExamTest.
        'SRC-UBI',
    ];

    private const VERIFIED_COURSE_CODES_WITH_A_DOCUMENTED_OFFICIAL_THRESHOLD = [
        'ADD-BIN-SEGEL', 'SRC', 'FKN', 'UBI', 'SBF-SEE', 'SBF-BIN-MOTOR', 'ADD-SEE-TO-BIN', 'ADD-BIN-TO-SEE', 'ADD-UBI-TO-SRC',
    ];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_courses_without_a_documented_official_passing_threshold_are_not_marked_as_verified(): void
    {
        foreach (self::UNVERIFIED_COURSE_CODES as $code) {
            $course = $this->onAdmin(fn () => CourseDefinition::withoutGlobalScopes()->where('code', $code)->whereNull('tenant_id')->firstOrFail());
            $ruleSet = $this->onAdmin(fn () => ExamRuleSet::where('course_id', $course->id)->first());

            $this->assertNotNull($ruleSet, "Kein ExamRuleSet für {$code} gefunden.");
            $this->assertFalse(
                $ruleSet->isVerified(),
                "{$code} darf nicht als verifiziert gelten, solange passing_rule nur den generischen 75%-Platzhalter nutzt statt einer amtlichen Bestehensgrenze."
            );
        }
    }

    public function test_starting_a_real_exam_paper_for_an_unverified_course_is_still_blocked_end_to_end(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-BIN-SEGEL');
        $this->grantEntitlement($tenant, $learner, $course);
        $paper = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->firstOrFail());

        $response = $this->actingAsInTenant($learner, $tenant)
            ->post("/courses/{$course->id}/exam/papers/{$paper->id}");

        $response->assertStatus(409);
    }

    /**
     * Bug-Review B5 (Folgefehler): startPaper() lehnt einen Versuch für ein
     * nicht freigegebenes Regelwerk bereits mit 409 ab -- die Bögen-
     * Übersicht zeigte die "Bogen N"-Start-Buttons aber unverändert
     * klickbar an, ein Klick führte also ins Leere statt den Kurs klar als
     * noch nicht freigegeben zu kennzeichnen.
     */
    public function test_papers_overview_hides_the_start_buttons_for_an_unverified_course(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-BIN-SEGEL');
        $this->grantEntitlement($tenant, $learner, $course);
        $paper = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->orderBy('paper_number')->firstOrFail());

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/exam");

        $response->assertOk();
        $response->assertSee('noch nicht freigegeben');
        $response->assertDontSee(route('exam.papers.start', ['course' => $course, 'paper' => $paper]), false);
    }

    public function test_papers_overview_still_shows_the_start_buttons_for_a_verified_course(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);
        $paper = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->orderBy('paper_number')->firstOrFail());

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/exam");

        $response->assertOk();
        $response->assertDontSee('noch nicht freigegeben');
        $response->assertSee(route('exam.papers.start', ['course' => $course, 'paper' => $paper]), false);
    }

    public function test_courses_with_a_documented_official_passing_threshold_remain_verified(): void
    {
        foreach (self::VERIFIED_COURSE_CODES_WITH_A_DOCUMENTED_OFFICIAL_THRESHOLD as $code) {
            $course = $this->onAdmin(fn () => CourseDefinition::withoutGlobalScopes()->where('code', $code)->whereNull('tenant_id')->firstOrFail());
            $ruleSet = $this->onAdmin(fn () => ExamRuleSet::where('course_id', $course->id)->first());

            $this->assertNotNull($ruleSet, "Kein ExamRuleSet für {$code} gefunden.");
            $this->assertTrue($ruleSet->isVerified(), "{$code} hat eine dokumentierte amtliche Bestehensgrenze und muss verifiziert bleiben.");
        }
    }

    public function test_the_seeder_source_no_longer_lists_a_course_without_an_explicit_passing_rule_override_as_verified(): void
    {
        $source = file_get_contents(base_path('database/seeders/ExamRuleSetSeeder.php'));

        preg_match('/\$verifiedCourses = \[(.*?)\];/', $source, $matches);
        $this->assertNotEmpty($matches, 'Konnte $verifiedCourses in ExamRuleSetSeeder.php nicht finden.');

        foreach (self::UNVERIFIED_COURSE_CODES as $code) {
            $this->assertStringNotContainsString(
                "'{$code}'",
                $matches[1],
                "{$code} darf nicht in \$verifiedCourses stehen, solange kein expliziter \$passingRuleOverrides-Eintrag mit einer amtlichen Bestehensgrenze existiert."
            );
        }
    }
}
