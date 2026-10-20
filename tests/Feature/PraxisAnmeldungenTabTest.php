<?php

namespace Tests\Feature;

use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Der "Praxis-Anmeldungen"-Tab im Bootsschul-Admin listet eingereichte
 * Praxisformulare (siehe PraxisFormSubmission) ihrer eigenen Lerner --
 * damit die Bootsschule die Daten an die Prüfungskommission weitergeben
 * kann.
 */
class PraxisAnmeldungenTabTest extends TestCase
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

    public function test_admin_sees_submissions_of_their_own_tenant(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner', ['display_name' => 'E2E Lerner']);
        $course = $this->existingCourse('SRC');

        $template = $this->onAdmin(function () {
            $template = PraxisFormTemplate::create([
                'title' => 'E2E Formular',
                'min_progress_percent' => 0,
                'course_ids' => [],
                'fields' => [
                    ['key' => 'telefonnummer', 'label' => 'Telefonnummer', 'type' => 'text', 'required' => true, 'options' => [], 'help_text' => null],
                ],
                'active' => true,
            ]);
            $this->createdTemplateIds[] = $template->id;

            return $template;
        });

        $this->onAdmin(fn () => PraxisFormSubmission::create([
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $learner->id,
            'praxis_form_template_id' => $template->id,
            'data' => ['telefonnummer' => '+49 170 1234567'],
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=praxis-anmeldungen');

        $response->assertOk();
        $response->assertSee('E2E Lerner');
        $response->assertSee('E2E Formular');
        $response->assertSee('+49 170 1234567');
    }

    public function test_admin_does_not_see_submissions_of_other_tenants(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $adminB = $this->createTenantUser($tenantB, 'owner');
        $learnerA = $this->createTenantUser($tenantA, 'learner', ['display_name' => 'E2E Nur A']);
        $course = $this->existingCourse('SRC');

        $template = $this->onAdmin(function () {
            $template = PraxisFormTemplate::create([
                'title' => 'E2E Formular',
                'min_progress_percent' => 0,
                'course_ids' => [],
                'fields' => [],
                'active' => true,
            ]);
            $this->createdTemplateIds[] = $template->id;

            return $template;
        });

        $this->onAdmin(fn () => PraxisFormSubmission::create([
            'tenant_id' => $tenantA->id,
            'course_id' => $course->id,
            'user_id' => $learnerA->id,
            'praxis_form_template_id' => $template->id,
            'data' => [],
        ]));

        $response = $this->actingAsInTenant($adminB, $tenantB)->get('/admin?tab=praxis-anmeldungen');

        $response->assertOk();
        $response->assertDontSee('E2E Nur A');
    }
}
