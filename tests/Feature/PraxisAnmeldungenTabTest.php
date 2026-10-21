<?php

namespace Tests\Feature;

use App\Models\PraxisExamAppointment;
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

        $submission = $this->onAdmin(fn () => PraxisFormSubmission::create([
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $learner->id,
            'praxis_form_template_id' => $template->id,
            'data' => ['telefonnummer' => '+49 170 1234567'],
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=praxis-anmeldungen');

        $response->assertOk();
        $response->assertSee('E2E Lerner');
        $response->assertSee('Prüfungstermin vorschlagen');

        $detail = $this->actingAsInTenant($admin, $tenant)->get(route('admin.praxis-form-submissions.show', $submission));
        $detail->assertOk();
        $detail->assertSee('E2E Lerner');
        $detail->assertSee('E2E Formular');
        $detail->assertSee('+49 170 1234567');
    }

    public function test_search_filters_submissions_by_name(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $match = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Findbar Franz']);
        $noMatch = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Anderer Anton']);
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

        [$matchSubmission, $noMatchSubmission] = $this->onAdmin(function () use ($tenant, $match, $noMatch, $course, $template) {
            $matchSubmission = PraxisFormSubmission::create([
                'tenant_id' => $tenant->id, 'course_id' => $course->id, 'user_id' => $match->id,
                'praxis_form_template_id' => $template->id, 'data' => [],
            ]);
            $noMatchSubmission = PraxisFormSubmission::create([
                'tenant_id' => $tenant->id, 'course_id' => $course->id, 'user_id' => $noMatch->id,
                'praxis_form_template_id' => $template->id, 'data' => [],
            ]);

            return [$matchSubmission, $noMatchSubmission];
        });

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=praxis-anmeldungen&anmeldung_search=Findbar');

        // Beide Lernernamen tauchen im übrigen Dashboard (z. B. Teilnehmer-
        // Tab) ohnehin auf, da alle Tabs serverseitig gerendert und nur per
        // Alpine ein-/ausgeblendet werden -- daher hier über den eindeutigen
        // Detail-Link (enthält die Submission-UUID) statt über den Namen
        // geprüft, ob der Filter tatsächlich wirkt.
        $response->assertOk();
        $response->assertSee(route('admin.praxis-form-submissions.show', $matchSubmission));
        $response->assertDontSee(route('admin.praxis-form-submissions.show', $noMatchSubmission));
    }

    public function test_table_shows_fixed_exam_date_as_next_step(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Fix Lerner']);
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

        $this->onAdmin(function () use ($tenant, $admin, $learner, $course, $template) {
            $submission = PraxisFormSubmission::create([
                'tenant_id' => $tenant->id,
                'course_id' => $course->id,
                'user_id' => $learner->id,
                'praxis_form_template_id' => $template->id,
                'data' => [],
            ]);

            $examDate = now()->addWeek();

            PraxisExamAppointment::create([
                'tenant_id' => $tenant->id,
                'praxis_form_submission_id' => $submission->id,
                'slot' => 0,
                'label' => 'Prüfungstermin',
                'round' => 1,
                'exam_date' => $examDate,
                'response' => 'accepted',
                'responded_at' => now(),
                'proposed_by_user_id' => $admin->id,
            ]);

            $submission->update(['confirmed_exam_date' => $examDate]);
        });

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=praxis-anmeldungen');

        $response->assertOk();
        $response->assertSee('Fix Lerner');
        $response->assertSee('Prüfungstermin fix:');
    }

    public function test_admin_cannot_view_submission_detail_of_other_tenant(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $adminB = $this->createTenantUser($tenantB, 'owner');
        $learnerA = $this->createTenantUser($tenantA, 'learner');
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

        $submission = $this->onAdmin(fn () => PraxisFormSubmission::create([
            'tenant_id' => $tenantA->id,
            'course_id' => $course->id,
            'user_id' => $learnerA->id,
            'praxis_form_template_id' => $template->id,
            'data' => [],
        ]));

        $this->actingAsInTenant($adminB, $tenantB)
            ->get(route('admin.praxis-form-submissions.show', $submission))
            ->assertNotFound();
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
