<?php

namespace Tests\Feature;

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VideoLesson;
use App\Models\VideoModule;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review: mehrere Filterparameter kamen ungeprüft aus der Query-String
 * direkt in ein where(...)/whereIn(...) gegen eine uuid-Spalte -- ein
 * manipulierter, nicht-UUID-förmiger Wert (z. B. von Hand in die URL
 * eingetragen) ließ Postgres mit "invalid input syntax for type uuid"
 * scheitern. Das endete als unbehandelte QueryException mit HTTP 500,
 * statt den Filter einfach zu ignorieren -- analog zum bereits
 * behandelten Fall anmeldung_from/anmeldung_to (siehe AdminDashboardController).
 */
class ManipulatedFilterParamsTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    private array $createdQuestionIds = [];

    protected function tearDown(): void
    {
        // Reihenfolge wichtig: zuerst Tenants/Nutzer (löscht u. a. die
        // Entitlements, die sonst die Löschung des Kurses per Fremdschlüssel
        // blockieren würden), dann der Kurs selbst (kaskadiert auf
        // VideoModule/VideoLesson/video_lesson_question), erst danach die
        // Frage -- sonst verletzt deren Löschung noch die Fremdschlüssel-
        // Zwangsbedingung der Pivot-Tabelle.
        $this->cleanUpCreatedTenantsAndUsers();

        $this->onAdmin(function () {
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }
            foreach ($this->createdQuestionIds as $id) {
                ContentQuestion::where('id', $id)->delete();
            }
        });

        parent::tearDown();
    }

    public function test_video_course_page_ignores_an_invalid_kapitel_filter_instead_of_erroring(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/video?kapitel=foo");

        $response->assertStatus(200);
    }

    public function test_video_course_page_ignores_a_kapitel_filter_submitted_as_an_array(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/video?kapitel[]=foo");

        $response->assertStatus(200);
    }

    public function test_admin_dashboard_ignores_an_invalid_anmeldung_course_filter_instead_of_erroring(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->get('/admin?tab=praxis-anmeldungen&anmeldung_course=foo');

        $response->assertStatus(200);
    }

    public function test_superadmin_coupons_index_ignores_an_invalid_tenant_id_filter_instead_of_erroring(): void
    {
        $admin = $this->createSuperAdmin();

        $response = $this->actingAs($admin)->get('/superadmin/coupons?tenant_id=foo');

        $response->assertStatus(200);
    }

    /**
     * Bug-Review (Query-Parameter-Validierung): ?questions=a,b wird als
     * String erwartet (LearningController::parseQuestionIds()) -- ein
     * manipulierter Request mit ?questions[]=a&questions[]=b lieferte
     * stattdessen ein Array, das array_filter(explode(',', ...)) mit
     * einem TypeError (strikter ?string-Typehint) scheitern ließ.
     */
    public function test_learning_show_ignores_a_questions_filter_submitted_as_an_array(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/learn?questions[]=foo&questions[]=bar");

        $response->assertStatus(200);
    }

    /**
     * Bug-Review (Query-Parameter-Validierung): dieselbe Lücke wie oben,
     * nur in VideoQuizController::resolveQuestions() -- explode(',', ...)
     * ohne is_string()-Schutz.
     */
    public function test_video_quiz_ignores_a_questions_filter_submitted_as_an_array(): void
    {
        [$tenant, $learner, $course, $lesson] = $this->makeIsolatedVideoLessonWithQuestion();

        $response = $this->actingAsInTenant($learner, $tenant)
            ->get("/courses/{$course->id}/video/{$lesson->id}/questions/1?questions[]=foo&questions[]=bar");

        $response->assertStatus(200);
    }

    /**
     * Bug-Review (Query-Parameter-Validierung): die zweite, eigene Kopie
     * von parseModuleIds() in VideoQuizController (kapitel) hatte den
     * is_string()-Schutz von oben (VideoCourseController) nie erhalten.
     */
    public function test_video_quiz_ignores_a_kapitel_filter_submitted_as_an_array(): void
    {
        [$tenant, $learner, $course, $lesson] = $this->makeIsolatedVideoLessonWithQuestion();

        $response = $this->actingAsInTenant($learner, $tenant)
            ->get("/courses/{$course->id}/video/{$lesson->id}/questions/1?kapitel[]=foo&kapitel[]=bar");

        $response->assertStatus(200);
    }

    /**
     * Bug-Review (Query-Parameter-Validierung): wb_search wird als String
     * erwartet (AdminDashboardController) -- ein manipulierter Request
     * mit ?wb_search[]=a lieferte stattdessen ein Array, das sowohl beim
     * Filtern (trim() ohne (string)-Cast, echter TypeError) als auch
     * beim Anzeigen (admin/tabs/willkommensbox.blade.php, {{ }} auf ein
     * Array, "Array to string conversion") scheiterte.
     */
    public function test_admin_dashboard_ignores_a_wb_search_filter_submitted_as_an_array(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->get('/admin?tab=willkommensbox&wb_search[]=foo&wb_search[]=bar');

        $response->assertStatus(200);
    }

    /**
     * Isolierter Videokurs mit genau einer Frage auf Position 1 -- die
     * Video-Quiz-Route braucht eine echte Frage an dieser Position, sonst
     * antwortet sie bereits ohne jeden manipulierten Parameter mit 404.
     * Bewusst nicht der echte Kurs SBF-SEE (siehe Bug-Review "Tests lassen
     * Daten liegen").
     *
     * @return array{0: Tenant, 1: User, 2: CourseDefinition, 3: VideoLesson}
     */
    private function makeIsolatedVideoLessonWithQuestion(): array
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        [$course, $lesson] = $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-VIDEO-FILTER-'.uniqid(),
                'name' => 'Test Videokurs',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $module = VideoModule::create(['course_id' => $course->id, 'title' => 'Test-Kapitel', 'sort_order' => 1]);
            $lesson = VideoLesson::create(['video_module_id' => $module->id, 'title' => 'Lektion 1', 'video_url' => 'https://example.test/1.mp4', 'sort_order' => 1]);

            $question = ContentQuestion::create([
                'content_id' => 'TEST-VQ-FILTER-'.uniqid(),
                'language' => 'de-DE',
                'active' => true,
            ]);
            $this->createdQuestionIds[] = $question->id;

            $revision = ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => 1,
                'question_type' => 'single_choice',
                'question_text' => 'E2E Filter-Test: Was bedeutet Steuerbord?',
                'editorial_status' => 'published',
                'rights_status' => 'licensed',
            ]);
            ContentAnswer::create(['revision_id' => $revision->id, 'answer_key' => 'A', 'answer_text' => 'Rechte Schiffsseite', 'is_correct' => true, 'sort_order' => 1]);
            ContentAnswer::create(['revision_id' => $revision->id, 'answer_key' => 'B', 'answer_text' => 'Linke Schiffsseite', 'is_correct' => false, 'sort_order' => 2]);

            $lesson->questions()->attach($question->id, ['sort_order' => 1]);

            return [$course, $lesson];
        });

        $this->grantEntitlement($tenant, $learner, $course);

        return [$tenant, $learner, $course, $lesson];
    }
}
