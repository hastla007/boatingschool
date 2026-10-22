<?php

namespace Tests\Feature;

use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review B28: Die Initialen-Avatare nutzten substr()/strtoupper() statt
 * mb_substr()/mb_strtoupper() -- bei einem mit einem Umlaut beginnenden
 * Namen wurde dadurch nur das erste Byte der UTF-8-Sequenz abgeschnitten
 * (zerstörtes/ungültiges Zeichen) statt des vollen Buchstabens.
 */
class InitialsUmlautTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdTemplateIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        $this->onAdmin(function () {
            foreach ($this->createdTemplateIds as $id) {
                PraxisFormTemplate::where('id', $id)->delete();
            }
        });

        parent::tearDown();
    }

    public function test_the_participant_detail_page_shows_the_full_umlaut_initial(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Ägidius Testmann']);

        $response = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");

        $response->assertOk();
        $this->assertSame('Ä', $this->extractAvatarInitial($response->getContent()));
    }

    public function test_the_participants_list_shows_the_full_umlaut_initial(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $this->createTenantUser($tenant, 'learner', ['display_name' => 'Ölrich Testmann']);

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=participants');

        $response->assertOk();
        preg_match('/>\s*([^\s<]+)\s*<\/div>\s*<span[^>]*>Ölrich Testmann</', $response->getContent(), $matches);
        $this->assertSame('Ö', $matches[1] ?? null);
    }

    public function test_the_praxis_submission_detail_page_shows_the_full_umlaut_initial(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Übel Testmann']);
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $template = $this->onAdmin(function () {
            $template = PraxisFormTemplate::create([
                'title' => 'B28 Testformular',
                'min_progress_percent' => 0,
                'course_ids' => [],
                'fields' => [],
                'active' => true,
            ]);
            $this->createdTemplateIds[] = $template->id;

            return $template;
        });

        $submission = $this->onAdmin(fn () => PraxisFormSubmission::create([
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $learner->id,
            'praxis_form_template_id' => $template->id,
            'data' => [],
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->get("/admin/praxis-form-submissions/{$submission->id}");

        $response->assertOk();
        $this->assertSame('Ü', $this->extractAvatarInitial($response->getContent()));
    }

    private function extractAvatarInitial(string $html): ?string
    {
        preg_match('/rounded-full flex items-center justify-center text-white[^>]*>\s*([^\s<]*)\s*</', $html, $matches);

        return $matches[1] ?? null;
    }
}
