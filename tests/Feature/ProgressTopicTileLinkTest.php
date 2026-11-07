<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ProgressTopicTileLinkTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    public function test_topic_spanning_two_modules_links_without_a_module(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE-BIN');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/progress")->assertOk();
        $tile = $response->viewData('byTopic')->get('Vorschriften & Pflichten');
        $this->assertNull($tile['module'], 'Thema liegt in zwei Modulen');
        $link = route('learning.show', ['course' => $course, 'mode' => 'topic', 'topic' => 'Vorschriften & Pflichten']);
        $this->assertStringContainsString(e($link), $response->getContent());
    }
}
