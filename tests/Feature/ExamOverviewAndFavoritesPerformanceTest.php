<?php

namespace Tests\Feature;

use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamPaperQuestion;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\ExamSessionQuestion;
use App\Models\Favorite;
use App\Models\Module;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Langsame Prüfungsübersicht und Favoriten-Seite):
 *
 * 1. ExamController::papersOverview() rief für jeden Bogen eine eigene
 *    COUNT-Query auf paperQuestions() auf, und für jeden bereits
 *    ausgewerteten Versuch eine weitere eigene COUNT-Query auf
 *    questions()->where('correct', true) -- bei N Bögen bis zu 2N
 *    zusätzliche Round-Trips. Beide Zählungen laufen jetzt als je eine
 *    gruppierte Aggregat-Query vorab.
 *
 * 2. learning/_favorite-card.blade.php rief für jede angezeigte Karte
 *    (Favorit oder "Fehler"-Frage) $question->publishedRevision() auf --
 *    das fragt IMMER frisch die Datenbank ab, selbst wenn die
 *    revisions-Relation bereits eager geladen ist. Bei vielen Favoriten
 *    bzw. Fehlern ergab das eine zusätzliche Query pro Karte. Die bereits
 *    geladene Relation wird jetzt direkt genutzt, wenn sie vorliegt.
 *
 * Analog zu Bug-Review B6 wird hier verglichen: die Query-Zahl für eine
 * kleine und eine größere Menge (Bögen bzw. Favoriten/Fehler) -- die
 * DIFFERENZ darf mit mehr Datensätzen nicht weiter wachsen.
 */
class ExamOverviewAndFavoritesPerformanceTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    private array $createdRuleSetIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        $this->onAdmin(function () {
            foreach ($this->createdRuleSetIds as $id) {
                ExamRuleSet::where('id', $id)->delete();
            }
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }
        });

        parent::tearDown();
    }

    private function queryCountForExamOverview(int $paperCount): int
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        [$course, $papers] = $this->onAdmin(function () use ($paperCount) {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-Z3-'.uniqid(),
                'name' => 'Test Prüfungsübersicht',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $ruleSet = ExamRuleSet::create([
                'course_id' => $course->id,
                'version' => 'test-'.uniqid(),
                'time_limit_seconds' => 3600,
                'passing_rule' => ['min_correct_ratio' => 0.75],
                'status' => 'published',
                'verified_at' => now(),
            ]);
            $this->createdRuleSetIds[] = $ruleSet->id;

            $module = Module::where('code', 'SBF_BASIS')->firstOrFail();
            $questions = $module->questions()
                ->whereHas('revisions', fn ($q) => $q->where('editorial_status', 'published'))
                ->orderBy('content_question.content_id')
                ->take(2)
                ->get();

            $papers = collect();
            foreach (range(1, $paperCount) as $n) {
                $paper = ExamPaper::create(['course_id' => $course->id, 'paper_number' => $n, 'sort_order' => $n]);
                foreach ($questions as $i => $question) {
                    ExamPaperQuestion::create(['exam_paper_id' => $paper->id, 'question_id' => $question->id, 'position' => $i + 1]);
                }
                $papers->push($paper);
            }

            return [$course, $papers];
        });

        $this->grantEntitlement($tenant, $learner, $course);

        // Jeder Bogen bekommt einen bereits ausgewerteten Versuch, damit
        // auch der zweite N+1-Pfad (correct-Zählung pro Versuch) greift.
        $this->onAdmin(function () use ($tenant, $learner, $course, $papers) {
            foreach ($papers as $paper) {
                $session = ExamSession::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $learner->id,
                    'course_id' => $course->id,
                    'rule_set_id' => $this->createdRuleSetIds[0],
                    'paper_id' => $paper->id,
                    'status' => 'evaluated',
                    'started_at' => now(),
                    'submitted_at' => now(),
                    'score' => 1.0,
                    'passed' => true,
                ]);

                foreach ($paper->paperQuestions as $i => $pq) {
                    ExamSessionQuestion::create([
                        'tenant_id' => $tenant->id,
                        'exam_session_id' => $session->id,
                        'question_id' => $pq->question_id,
                        'revision_id' => ContentQuestionRevision::where('question_id', $pq->question_id)->where('editorial_status', 'published')->firstOrFail()->id,
                        'position' => $i + 1,
                        'correct' => true,
                        'answered_at' => now(),
                    ]);
                }
            }
        });

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/exam");
        $response->assertOk();

        return $queryCount;
    }

    public function test_exam_papers_overview_query_count_does_not_scale_with_paper_count(): void
    {
        $small = $this->queryCountForExamOverview(2);
        $large = $this->queryCountForExamOverview(12);

        $this->assertLessThanOrEqual(3, $large - $small, "Prüfungsübersicht braucht bei 12 Bögen {$large} Queries statt {$small} bei 2 -- die Differenz wächst mit mehr Bögen, vermutlich wieder ein N+1.");
    }

    private function queryCountForFavoritesPage(int $favoriteCount): int
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('SRC');
        $questions = $module->questions()
            ->whereHas('revisions', fn ($q) => $q->where('editorial_status', 'published'))
            ->orderBy('content_question.content_id')
            ->take($favoriteCount)
            ->get();
        $this->assertGreaterThanOrEqual($favoriteCount, $questions->count());

        $this->onAdmin(function () use ($tenant, $learner, $questions) {
            foreach ($questions as $question) {
                Favorite::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $learner->id,
                    'question_id' => $question->id,
                    'context' => Favorite::CONTEXT_SMART_LEARNING,
                ]);
            }
        });

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('favorites.smart-learning', $course));
        $response->assertOk();

        return $queryCount;
    }

    public function test_favorites_page_query_count_does_not_scale_with_favorite_count(): void
    {
        $small = $this->queryCountForFavoritesPage(2);
        $large = $this->queryCountForFavoritesPage(10);

        $this->assertLessThanOrEqual(3, $large - $small, "Favoriten-Seite braucht bei 10 Favoriten {$large} Queries statt {$small} bei 2 -- die Differenz wächst mit mehr Favoriten (publishedRevision() pro Karte?), vermutlich wieder ein N+1.");
    }
}
