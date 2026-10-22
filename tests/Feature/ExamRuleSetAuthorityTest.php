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
 * amtlichen Bestehensgrenze (ADD-BIN-SEGEL, SRC, FKN) dürfen verifiziert
 * sein.
 */
class ExamRuleSetAuthorityTest extends TestCase
{
    use InteractsWithTenants;

    private const UNVERIFIED_COURSE_CODES = [
        'SBF-SEE', 'SBF-BIN-MOTOR', 'SBF-SEE-BIN', 'ADD-SEE-TO-BIN', 'ADD-BIN-TO-SEE', 'SBF-BIN-SEGEL',
    ];

    private const VERIFIED_COURSE_CODES_WITH_A_DOCUMENTED_OFFICIAL_THRESHOLD = ['ADD-BIN-SEGEL', 'SRC', 'FKN'];

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
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);
        $paper = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->firstOrFail());

        $response = $this->actingAsInTenant($learner, $tenant)
            ->post("/courses/{$course->id}/exam/papers/{$paper->id}");

        $response->assertStatus(409);
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
