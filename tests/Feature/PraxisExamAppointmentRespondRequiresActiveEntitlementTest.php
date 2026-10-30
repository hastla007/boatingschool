<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\PraxisExamAppointment;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Prüfungstermin bestätigbar trotz bereits entzogenem
 * Kurszugang): PraxisPruefungController prüft das Entitlement nur beim
 * Aufruf der Seite -- ein Admin kann den Kurszugang aber jederzeit
 * zwischen Seitenaufruf und Absenden widerrufen (siehe W3/T5 für dasselbe
 * Muster bei der Coupon-Einlösung). PraxisExamAppointmentController::
 * respond() prüfte das nie erneut.
 */
class PraxisExamAppointmentRespondRequiresActiveEntitlementTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdTemplateIds = [];

    private array $createdCourseIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        if ($this->createdTemplateIds !== []) {
            $this->onAdmin(fn () => PraxisFormTemplate::whereIn('id', $this->createdTemplateIds)->delete());
        }

        if ($this->createdCourseIds !== []) {
            $this->onAdmin(fn () => CourseDefinition::withoutGlobalScopes()->whereIn('id', $this->createdCourseIds)->delete());
        }

        parent::tearDown();
    }

    public function test_responding_to_a_proposal_is_rejected_once_the_entitlement_has_been_revoked(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');

        $course = $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-EXAM-APPT-REVOKED-'.uniqid(),
                'name' => 'Test-Kurs für widerrufenen Prüfungstermin',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            return $course;
        });

        $entitlement = $this->grantEntitlement($tenant, $learner, $course);

        $template = $this->onAdmin(function () use ($course) {
            $template = PraxisFormTemplate::create([
                'title' => 'E2E Termin-Widerruf-Formular',
                'min_progress_percent' => 0,
                'course_ids' => [$course->id],
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

        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => now()->addWeek()->format('Y-m-d\TH:i'), 'slot' => 0]
        );
        $appointment = $this->onAdmin(fn () => PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->where('slot', 0)->first());

        $this->onAdmin(fn () => Entitlement::where('id', $entitlement->id)->update(['status' => 'revoked']));

        $response = $this->actingAsInTenant($learner, $tenant)->post(
            route('praxis-pruefung.exam-appointments.respond', [$course->id, $appointment->id]),
            ['decision' => 'accepted']
        );

        $response->assertForbidden();

        $appointment->refresh();
        $this->assertSame('pending', $appointment->response);
    }

    public function test_responding_still_works_with_an_active_entitlement(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');

        $course = $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-EXAM-APPT-ACTIVE-'.uniqid(),
                'name' => 'Test-Kurs für aktiven Prüfungstermin',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            return $course;
        });

        $this->grantEntitlement($tenant, $learner, $course);

        $template = $this->onAdmin(function () use ($course) {
            $template = PraxisFormTemplate::create([
                'title' => 'E2E Termin-Aktiv-Formular',
                'min_progress_percent' => 0,
                'course_ids' => [$course->id],
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

        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => now()->addWeek()->format('Y-m-d\TH:i'), 'slot' => 0]
        );
        $appointment = $this->onAdmin(fn () => PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->where('slot', 0)->first());

        $response = $this->actingAsInTenant($learner, $tenant)->post(
            route('praxis-pruefung.exam-appointments.respond', [$course->id, $appointment->id]),
            ['decision' => 'accepted']
        );

        $response->assertRedirect();
        $this->assertSame('accepted', $appointment->fresh()->response);
    }
}
