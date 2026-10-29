<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\ExamPaper;
use App\Models\ExamSession;
use App\Models\VideoModule;
use App\Support\ComboVideoGroups;
use Database\Seeders\FunkVideoCourseSeeder;
use Database\Seeders\SrcUbiComboExamPaperSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Aufbau der Funkkurse: Smart-Learning nach Katalogkapiteln, der
 * Kombikurs SRC & UBI lernt SRC plus UBI-Ergänzung und hat die
 * Bogengruppen SRC, UBI und UBI-Ergänzung, und alle Funkkurse haben einen
 * Videokurs mit je einer Teilkurs-Karte für SRC und UBI im Kombikurs.
 */
class FunkCourseStructureTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_combo_learns_src_plus_the_ubi_addon_catalog(): void
    {
        $this->onAdmin(function () {
            $combo = $this->existingCourse('SRC-UBI');

            $this->assertSame(['SRC', 'UBI_ERG'], $combo->modules()->pluck('code')->all());
            $this->assertSame(259, $combo->modules->flatMap(fn ($m) => $m->questions)->pluck('id')->unique()->count());
        });
    }

    public function test_smart_learning_groups_src_and_ubi_by_catalog_chapter(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC-UBI');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/learn/overview");

        $response->assertOk();
        foreach (['SAR / EPIRB / SART', 'GMDSS', 'DSC', 'NAVTEX', 'Verkehrskreise', 'Binnenschifffahrtsfunk'] as $chapter) {
            $response->assertSee($chapter);
        }
        $response->assertSee('0/56 Fragen');

        $this->onAdmin(function () {
            $categories = fn (string $prefix) => ContentQuestionRevision::where('editorial_status', 'published')
                ->whereIn('question_id', ContentQuestion::where('content_id', 'like', "{$prefix}-%")->pluck('id'))
                ->distinct()->orderBy('smartmodus_kategorie')->pluck('smartmodus_kategorie')->all();

            $this->assertSame(['Betriebsverfahren', 'DSC', 'Funkeinrichtungen', 'GMDSS', 'NAVTEX', 'SAR / EPIRB / SART', 'UKW-Sprechfunk'], $categories('SRC'));
            $this->assertSame(['Betriebsverfahren', 'Binnenschifffahrtsfunk', 'Funkeinrichtungen', 'Sprechfunk', 'Verkehrskreise'], $categories('UBI'));
        });
    }

    public function test_migration_sets_the_category_on_existing_revisions(): void
    {
        $migration = require database_path('migrations/2024_02_01_000478_set_smartmode_categories_for_radio_catalogs.php');

        $this->onAdmin(function () use ($migration) {
            $revision = ContentQuestion::where('content_id', 'SRC-0001')->firstOrFail()->publishedRevision();

            DB::beginTransaction();
            try {
                $revision->update(['smartmodus_kategorie' => null]);

                $migration->up();

                $this->assertSame($revision->subtopic, $revision->fresh()->smartmodus_kategorie);
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_combo_exam_has_src_ubi_and_ubi_addon_groups(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC-UBI');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/exam");

        $response->assertOk();
        $response->assertSeeInOrder(['SRC', 'UBI', 'UBI-Ergänzung']);
        $response->assertSee('Bestanden ab 8 von 10 richtigen Antworten · 30 Minuten');
        $response->assertSee('UBI-Ergänzungsbogen');
    }

    public function test_ubi_addon_paper_in_the_combo_uses_the_addon_rule_set(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC-UBI');
        $this->grantEntitlement($tenant, $learner, $course);
        $paper = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->where('paper_number', 201)->firstOrFail());

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}")->assertRedirect();

        [$session, $questions] = $this->asTenant($tenant, function () use ($tenant, $learner) {
            $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();

            return [$session, $session->questions()->orderBy('position')->with('revision.answers')->get()];
        });
        $this->assertCount(10, $questions);

        foreach ($questions as $i => $sq) {
            $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                'position' => $sq->position,
                'answer_id' => $sq->revision->answers->firstWhere('is_correct', $i < 8)->id,
            ]);
        }
        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/finish")->assertRedirect();

        $session = $this->asTenant($tenant, fn () => $session->fresh());
        $this->assertTrue($session->passed);
        $this->assertSame($this->onAdmin(fn () => $this->existingCourse('ADD-UBI-TO-SRC')->activeExamRuleSet()->id), $session->rule_set_id);
    }

    public function test_seeder_adds_the_ubi_addon_group_to_an_existing_combo(): void
    {
        $this->onAdmin(function () {
            $combo = $this->existingCourse('SRC-UBI');

            DB::beginTransaction();
            try {
                ExamPaper::where('course_id', $combo->id)->where('paper_number', '>=', 201)->get()->each(function ($paper) {
                    $paper->paperQuestions()->delete();
                    $paper->delete();
                });
                $before = ExamPaper::where('course_id', $combo->id)->orderBy('paper_number')->pluck('id')->all();
                $this->assertCount(24, $before);

                (new SrcUbiComboExamPaperSeeder)->run();

                $after = ExamPaper::where('course_id', $combo->id)->orderBy('paper_number')->get();
                $this->assertCount(36, $after);
                $this->assertSame($before, $after->where('paper_number', '<', 201)->pluck('id')->values()->all());
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_combo_course_page_shows_one_video_card_per_radio_licence(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC-UBI');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertSee('Dein Videokurs für SRC');
        $response->assertSee('Dein Videokurs für UBI');
        $response->assertDontSee('noch kein Videokurs hinterlegt');
    }

    public function test_combo_video_groups_cover_exactly_the_seeded_radio_chapters(): void
    {
        $groups = collect(ComboVideoGroups::GROUPS['SRC-UBI'])->keyBy('label');

        $this->assertSame(array_keys(FunkVideoCourseSeeder::SRC_CHAPTERS), $groups['SRC']['chapters']);
        $this->assertSame(array_keys(FunkVideoCourseSeeder::UBI_CHAPTERS), $groups['UBI']['chapters']);

        $this->onAdmin(function () {
            foreach (['SRC' => 7, 'UBI' => 5, 'ADD-UBI-TO-SRC' => 5, 'SRC-UBI' => 12] as $code => $chapters) {
                $this->assertSame($chapters, VideoModule::where('course_id', $this->existingCourse($code)->id)->count(), "Videokapitel {$code}");
            }
        });
    }
}
