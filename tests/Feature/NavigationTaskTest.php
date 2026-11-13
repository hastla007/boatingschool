<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\NavigationProgress;
use App\Models\NavigationQuestion;
use App\Models\NavigationTask;
use App\Services\AccountErasureService;
use App\Services\PersonalDataExportService;
use App\Support\NavigationChart;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class NavigationTaskTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    protected function tearDown(): void
    {
        // Tenant zuerst (kaskadiert keine Navigationsdaten weg, die sind
        // zentraler Content), dann den isolierten Test-Kurs (kaskadiert
        // navigation_task/navigation_question weg).
        $this->cleanUpCreatedTenants();

        $this->onAdmin(function () {
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }
        });

        // Bug-Review Testisolation: fehlte hier -- jeder per
        // createTenantUser() angelegte Nutzer blieb dauerhaft stehen, weil
        // nur der Tenant (nicht aber der Nutzer selbst) aufgeräumt wurde.
        $this->cleanUpCreatedUsers();

        parent::tearDown();
    }

    public function test_learner_without_entitlement_cannot_view_navigation_tasks(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedNavigationTask();

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/exam/navigation");

        $response->assertForbidden();
    }

    public function test_navigation_task_page_shows_scenario_and_questions_with_answers(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedNavigationTask();
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/exam/navigation");

        $response->assertOk();
        $response->assertSee('Testszenario für die Navigationsaufgabe.');
        $response->assertSee('Wie lautet der Testkurs?');
        $response->assertSee('Musterlösung:');
        $response->assertSee('090°');
    }

    public function test_own_answer_is_checked_with_tolerance_and_progress_shown(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $task] = $this->makeIsolatedNavigationTask();
        $this->grantEntitlement($tenant, $learner, $course);
        $question = $this->onAdmin(fn () => $task->questions()->first());
        $url = "/courses/{$course->id}/exam/navigation/{$task->id}/questions/{$question->id}";

        $this->actingAsInTenant($learner, $tenant)->post($url, ['answer' => '095'])
            ->assertRedirect(route('exam.navigation.show', ['course' => $course, 'task' => $task]).'#frage-1');
        $progress = $this->onAdmin(fn () => NavigationProgress::where('user_id', $learner->id)->first());
        $this->assertSame('095', $progress->answer);
        $this->assertFalse($progress->correct);
        $this->assertSame($tenant->id, $progress->tenant_id);

        $this->actingAsInTenant($learner, $tenant)->post($url, ['answer' => '091°']);
        $this->assertTrue($this->onAdmin(fn () => NavigationProgress::where('user_id', $learner->id)->first())->correct);

        $page = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/exam/navigation/{$task->id}")->assertOk();
        $page->assertSee('Richtig')->assertSee('value="091°"', false)->assertSee('1 von 1 Aufgaben vollständig richtig');

        // Leere Antwort: Fehler, nichts überschrieben.
        $this->actingAsInTenant($learner, $tenant)->post($url, ['answer' => ''])->assertSessionHasErrors('answer_'.$question->id);
        $this->assertSame('091°', $this->onAdmin(fn () => NavigationProgress::where('user_id', $learner->id)->first())->answer);
    }

    public function test_descriptive_answers_are_self_assessed(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $task] = $this->makeIsolatedNavigationTask('Siehe Karte');
        $this->grantEntitlement($tenant, $learner, $course);
        $question = $this->onAdmin(fn () => $task->questions()->first());
        $url = "/courses/{$course->id}/exam/navigation/{$task->id}/questions/{$question->id}";

        $this->actingAsInTenant($learner, $tenant)->post($url, ['answer' => 'eingezeichnet']);
        $this->assertNull($this->onAdmin(fn () => NavigationProgress::where('user_id', $learner->id)->first())->correct);
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/exam/navigation/{$task->id}")->assertSee('Bitte selbst bewerten');

        $this->actingAsInTenant($learner, $tenant)->post($url, ['self' => 'richtig']);
        $progress = $this->onAdmin(fn () => NavigationProgress::where('user_id', $learner->id)->first());
        $this->assertTrue($progress->correct);
        $this->assertSame('eingezeichnet', $progress->answer, 'Selbstbewertung behält die Antwort');

        // Datenexport.
        $export = $this->onAdmin(fn () => app(PersonalDataExportService::class)->export($learner, $tenant));
        $this->assertSame('eingezeichnet', $export['navigation_progress'][0]['answer']);
    }

    public function test_navigation_progress_is_erased_when_leaving_the_school(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $task] = $this->makeIsolatedNavigationTask();
        $question = $this->onAdmin(fn () => $task->questions()->first());
        $this->onAdmin(fn () => NavigationProgress::create(['tenant_id' => $tenant->id, 'user_id' => $learner->id, 'navigation_question_id' => $question->id, 'answer' => '090', 'correct' => true]));

        $this->onAdmin(fn () => app(AccountErasureService::class)->leaveTenant($learner, $tenant));

        $this->assertSame(0, $this->onAdmin(fn () => NavigationProgress::where('user_id', $learner->id)->count()));
    }

    public function test_question_from_another_task_is_rejected(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $task] = $this->makeIsolatedNavigationTask();
        [, $otherTask] = $this->makeIsolatedNavigationTask();
        $this->grantEntitlement($tenant, $learner, $course);
        $foreign = $this->onAdmin(fn () => $otherTask->questions()->first());

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/navigation/{$task->id}/questions/{$foreign->id}", ['answer' => '090'])->assertNotFound();
    }

    public function test_superadmin_uploads_and_removes_a_chart_excerpt(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $superadmin = $this->createSuperAdmin();
        [$course, $task] = $this->makeIsolatedNavigationTask();

        $this->actingAs($superadmin)->get("/superadmin/courses/{$course->id}/navigation")->assertOk()->assertSee('Kein Kartenausschnitt');
        $this->actingAs($superadmin)->patch("/superadmin/navigation-tasks/{$task->id}/chart", ['chart_caption' => 'ohne Bild'])->assertSessionHasErrors('chart');

        $this->actingAs($superadmin)->patch("/superadmin/navigation-tasks/{$task->id}/chart", [
            'chart' => UploadedFile::fake()->image('karte.png', 800, 600),
            'chart_caption' => 'Ausschnitt mit freundlicher Genehmigung',
        ])->assertSessionHasNoErrors();
        $task = $this->onAdmin(fn () => $task->fresh());
        // Privat gespeichert, nicht öffentlich abrufbar.
        Storage::disk('local')->assertExists($task->chart_image_path);
        Storage::disk('public')->assertMissing($task->chart_image_path);
        $this->assertSame('Ausschnitt mit freundlicher Genehmigung', $task->chart_caption);

        // Lernende sehen den Ausschnitt mit Bildunterschrift.
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $learner, $course);
        $page = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/exam/navigation")
            ->assertSee($task->chart_image_path)->assertSee('Ausschnitt mit freundlicher Genehmigung');
        // Nur über die signierte, kurzlebige Adresse auslieferbar.
        preg_match('#src="([^"]*/media/navigation-charts/[^"]+)"#', $page->getContent(), $m);
        $this->assertNotEmpty($m, 'signierte Bildadresse auf der Seite');
        $signed = html_entity_decode($m[1]);
        $this->get($signed)->assertOk();
        $this->get(strtok($signed, '?'))->assertForbidden();
        $this->travel(NavigationChart::URL_MINUTES + 1)->minutes();
        $this->get($signed)->assertForbidden();
        $this->travelBack();

        $path = $task->chart_image_path;
        $this->actingAs($superadmin)->delete("/superadmin/navigation-tasks/{$task->id}/chart")->assertRedirect();
        Storage::disk('local')->assertMissing($path);
        $this->assertNull($this->onAdmin(fn () => $task->fresh())->chart_image_path);
    }

    /** @return array{0: CourseDefinition, 1: NavigationTask} */
    private function makeIsolatedNavigationTask(string $answer = '090°'): array
    {
        return $this->onAdmin(function () use ($answer) {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-NAV-'.uniqid(),
                'name' => 'Test Navigationskurs',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $task = NavigationTask::create([
                'course_id' => $course->id,
                'task_number' => 1,
                'scenario_text' => 'Testszenario für die Navigationsaufgabe.',
                'sort_order' => 1,
            ]);

            NavigationQuestion::create([
                'navigation_task_id' => $task->id,
                'question_number' => 1,
                'question_text' => 'Wie lautet der Testkurs?',
                'answer_text' => $answer,
                'sort_order' => 1,
            ]);

            return [$course, $task];
        });
    }
}
