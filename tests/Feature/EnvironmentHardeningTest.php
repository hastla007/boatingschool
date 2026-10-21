<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview M4: der ?as_tenant=-Dev-Override griff bisher allein
 * aufgrund von APP_ENV=local/testing -- übernähme eine produktive
 * Umgebung versehentlich die .env.example-Standardwerte, ließe sich der
 * Mandant von jedem Host aus umschalten. Zusätzlich fehlte eine
 * Eingabevalidierung für Routen-UUIDs und den "mode"-Parameter im
 * Lernmodus.
 */
class EnvironmentHardeningTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_dev_tenant_override_is_ignored_for_requests_not_from_localhost(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAs($learner)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->withSession(['dev_tenant_slug' => $tenant->slug])
            ->get('/dashboard');

        // Ohne aufgelösten Mandanten greift weder tenant.member noch die
        // reguläre Dashboard-Antwort -- der zentrale Picker/Not-found-View
        // wird stattdessen gerendert.
        $response->assertDontSee('Weiterlernen');
    }

    public function test_dev_tenant_override_still_works_from_localhost(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAs($learner)
            ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withSession(['dev_tenant_slug' => $tenant->slug])
            ->get('/dashboard');

        $response->assertOk();
    }

    public function test_a_malformed_uuid_route_parameter_returns_404_not_a_server_error(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/courses/not-a-uuid');

        $response->assertNotFound();
    }

    public function test_an_oversized_learning_mode_is_rejected_instead_of_breaking_the_database_insert(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('SBF_BASIS');
        $courseModule = $course->modules->first();
        $question = $courseModule->questions->first();
        $revision = $question->publishedRevision() ?? $question->revisions()->where('editorial_status', 'published')->first();

        $response = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/learn/attempts", [
            'revision_id' => $revision->id,
            'answer_id' => $revision->answers->first()->id ?? null,
            'mode' => str_repeat('x', 50),
        ]);

        $response->assertSessionHasErrors('mode');
    }

    public function test_an_unknown_learning_mode_query_param_falls_back_to_smarttrainer(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/learn?mode=".str_repeat('x', 60));

        $response->assertOk();
    }
}
