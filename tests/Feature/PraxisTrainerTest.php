<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\MediaAsset;
use App\Models\PraxisTask;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PraxisTrainerTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    protected function tearDown(): void
    {
        // Tenant zuerst (kaskadiert praxis_progress weg), dann den
        // isolierten Test-Kurs (kaskadiert praxis_task weg).
        $this->cleanUpCreatedTenants();

        $this->onAdmin(function () {
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }
        });

        parent::tearDown();
    }

    public function test_learner_without_entitlement_cannot_view_praxistrainer(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedPraxisTasks();

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/praxistrainer");

        $response->assertForbidden();
    }

    public function test_completing_a_task_advances_to_the_next_one_and_tracks_progress(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $firstTask, $secondTask] = $this->makeIsolatedPraxisTasks();
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->post(
            "/courses/{$course->id}/praxistrainer/{$firstTask->id}/complete"
        );

        $response->assertRedirect(route('praxistrainer.show', ['course' => $course, 'task' => $secondTask]));

        $this->assertDatabaseHas('praxis_progress', [
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'praxis_task_id' => $firstTask->id,
            'completed' => true,
        ]);

        $page = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/praxistrainer");
        $page->assertOk();
        $page->assertSee('1 / 2 Aufgaben', false);
    }

    public function test_kategorie_query_param_scopes_the_praxistrainer_to_a_single_category(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, , $taskB] = $this->makeIsolatedPraxisTasksWithTwoCategories();
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)
            ->get("/courses/{$course->id}/praxistrainer?kategorie=".urlencode('Kategorie B'));

        $response->assertOk();
        $response->assertSee('Kategorie B Aufgabe');
        $response->assertDontSee('Kategorie A Aufgabe');
        $response->assertSee('0 / 1 Aufgaben', false);
    }

    public function test_image_is_hidden_before_reveal_for_knots_but_shown_upfront_for_signs(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $knotTask, $signTask] = $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-PRAXIS-'.uniqid(),
                'name' => 'Test Praxistrainer',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $asset = MediaAsset::create([
                'asset_key' => 'test-praxis-image-'.uniqid(),
                'media_type' => 'image',
                'storage_path' => 'http://localhost/storage/question-media/praxistrainer/test-solution-image.png',
            ]);

            $knotTask = PraxisTask::create([
                'course_id' => $course->id,
                'content_id' => 'TEST-PRAXIS-KNOT',
                'kategorie' => 'Seemannsknoten',
                'frage' => 'Führe den Knoten „Testknoten“ vor.',
                'antwort' => 'Musterlösung Testknoten',
                'media_asset_id' => $asset->id,
                'sort_order' => 1,
            ]);
            $signTask = PraxisTask::create([
                'course_id' => $course->id,
                'content_id' => 'TEST-PRAXIS-SIGN',
                'kategorie' => 'Zeichen auf See',
                'frage' => 'Was bedeutet das dargestellte Zeichen?',
                'antwort' => 'Musterlösung Zeichen',
                'media_asset_id' => $asset->id,
                'sort_order' => 2,
            ]);

            return [$course, $knotTask, $signTask];
        });
        $this->grantEntitlement($tenant, $learner, $course);

        $knotPage = $this->actingAsInTenant($learner, $tenant)
            ->get(route('praxistrainer.show', ['course' => $course, 'task' => $knotTask]))
            ->getContent();
        $this->assertGreaterThan(
            strpos($knotPage, 'MUSTERLÖSUNG'),
            strpos($knotPage, 'test-solution-image.png'),
            'Für Knoten muss das Bild erst nach der Musterlösung im HTML stehen.'
        );

        $signPage = $this->actingAsInTenant($learner, $tenant)
            ->get(route('praxistrainer.show', ['course' => $course, 'task' => $signTask]))
            ->getContent();
        $this->assertLessThan(
            strpos($signPage, 'Musterlösung anzeigen'),
            strpos($signPage, 'test-solution-image.png'),
            'Für Zeichen muss das Bild schon vor dem Musterlösung-Button im HTML stehen.'
        );
    }

    /** @return array{0: CourseDefinition, 1: PraxisTask, 2: PraxisTask} */
    private function makeIsolatedPraxisTasks(): array
    {
        return $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-PRAXIS-'.uniqid(),
                'name' => 'Test Praxistrainer',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $first = PraxisTask::create([
                'course_id' => $course->id,
                'content_id' => 'TEST-PRAXIS-0001',
                'kategorie' => 'Testkategorie',
                'frage' => 'Testaufgabe 1',
                'antwort' => 'Musterlösung 1',
                'sort_order' => 1,
            ]);
            $second = PraxisTask::create([
                'course_id' => $course->id,
                'content_id' => 'TEST-PRAXIS-0002',
                'kategorie' => 'Testkategorie',
                'frage' => 'Testaufgabe 2',
                'antwort' => 'Musterlösung 2',
                'sort_order' => 2,
            ]);

            return [$course, $first, $second];
        });
    }

    /** @return array{0: CourseDefinition, 1: PraxisTask, 2: PraxisTask} */
    private function makeIsolatedPraxisTasksWithTwoCategories(): array
    {
        return $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-PRAXIS-'.uniqid(),
                'name' => 'Test Praxistrainer',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $taskA = PraxisTask::create([
                'course_id' => $course->id,
                'content_id' => 'TEST-PRAXIS-A1',
                'kategorie' => 'Kategorie A',
                'frage' => 'Kategorie A Aufgabe',
                'antwort' => 'Musterlösung A',
                'sort_order' => 1,
            ]);
            $taskB = PraxisTask::create([
                'course_id' => $course->id,
                'content_id' => 'TEST-PRAXIS-B1',
                'kategorie' => 'Kategorie B',
                'frage' => 'Kategorie B Aufgabe',
                'antwort' => 'Musterlösung B',
                'sort_order' => 2,
            ]);

            return [$course, $taskA, $taskB];
        });
    }
}
