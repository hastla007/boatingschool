<?php

namespace Tests\Feature\Api;

use App\Api\CourseAccess;
use App\Models\ExamPaper;
use App\Models\ExamSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Abfrage-Budgets der häufigen bzw. schweren Abläufe: Prüfungsstart,
 * Auswertung und Kursliste. Die Grenzen sind großzügig -- sie sollen
 * Rückfälle in „eine Abfrage je Frage/Kurs“ erkennen, nicht Feintuning.
 */
class QueryBudgetTest extends MobileApiTestCase
{
    private function countQueries(callable $call): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $call();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_exam_start_and_evaluation_do_not_query_per_question(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);
        $paperId = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->where('paper_number', 3)->value('id'));

        $exam = null;
        $start = $this->countQueries(function () use (&$exam, $course, $token, $tenant, $paperId) {
            $exam = $this->api('POST', "/courses/{$course->id}/exams", $token, $tenant, ['paper_id' => $paperId])->assertCreated()->json('data');
        });
        $this->assertCount(30, $exam['questions']);
        $this->assertCount(9, $exam['navigation']['parts']);
        $this->assertLessThan(70, $start, "Prüfungsstart: {$start} Abfragen");

        $this->onAdmin(fn () => DB::table('exam_session')->where('id', $exam['exam_id'])->update(['started_at' => now()->subSeconds(3600 + 20)]));
        $answers = $this->onAdmin(fn () => ExamSession::findOrFail($exam['exam_id'])->navigationAnswers()->get());
        $this->api('PUT', "/exams/{$exam['exam_id']}/navigation", $token, $tenant, ['answers' => $answers->mapWithKeys(fn ($a) => [$a->id => $a->modelAnswer()])->all()])->assertOk();

        $result = null;
        $evaluate = $this->countQueries(function () use (&$result, $exam, $token, $tenant, $answers) {
            $result = $this->api('POST', "/exams/{$exam['exam_id']}/navigation/assessment", $token, $tenant, ['known' => $answers->mapWithKeys(fn ($a) => [$a->id => true])->all()])->assertOk();
        });
        $this->assertSame(9, $result->json('data.navigation.points'));
        $this->assertSame(0, $result->json('data.correct_count'));
        $this->assertSame(30, $this->onAdmin(fn () => DB::table('exam_session_question')->where('exam_session_id', $exam['exam_id'])->where('correct', false)->count()));
        $this->assertLessThan(80, $evaluate, "Auswertung: {$evaluate} Abfragen");
    }

    public function test_warmed_course_list_stays_cheap_even_after_the_fresh_window(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        foreach (['SBF-SEE-BIN', 'SRC', 'FKN'] as $code) {
            $this->grantEntitlement($tenant, $user, $this->existingCourse($code));
        }
        $token = $this->apiLogin($user);

        $this->artisan('app:warm-course-cache')->assertSuccessful();
        $this->assertTrue(Cache::has(CourseAccess::cacheKey('capabilities', $this->existingCourse('SRC'))));

        $queries = $this->countQueries(fn () => $this->api('GET', '/courses?per_page=100', $token, $tenant)->assertOk());
        $this->assertLessThan(60, $queries, "Kursliste: {$queries} Abfragen");

        // Nach dem frischen Fenster: der veraltete Wert wird geliefert, die
        // Neuberechnung läuft nach der Antwort (im Test noch in derselben Anfrage).
        $this->travel(10)->minutes();
        $this->api('GET', '/courses?per_page=100', $this->apiLogin($user), $tenant)->assertOk()->assertJsonStructure(['data' => [['capabilities']]]);
    }
}
