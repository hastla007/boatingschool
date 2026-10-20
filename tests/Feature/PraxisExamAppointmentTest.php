<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\PraxisExamAppointment;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Models\Tenant;
use App\Models\User;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Prüfungstermin-Vorschlagsrunden zu einer Praxis-Anmeldung (siehe
 * PraxisExamAppointmentService): die Bootsschule schlägt einen Termin vor
 * (auf der Teilnehmer-Detailseite), der Lerner nimmt an oder lehnt ab --
 * bei Ablehnung folgt eine neue Runde, bis ein Termin angenommen und
 * damit fixiert wird.
 */
class PraxisExamAppointmentTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdTemplateIds = [];

    private array $createdCourseIds = [];

    protected function tearDown(): void
    {
        // Erst Tenants (kaskadiert praxis_form_submission + den
        // Terminvorschlag weg), dann die global geteilten Fixtures.
        $this->cleanUpCreatedTenantsAndUsers();

        $this->onAdmin(function () {
            foreach ($this->createdTemplateIds as $id) {
                PraxisFormTemplate::where('id', $id)->delete();
            }
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }
        });

        parent::tearDown();
    }

    /**
     * Eigener isolierter Kurs statt eines echten Katalogkurses, da die
     * echten Kurse bereits einem Demo-Praxisformular zugewiesen sind
     * (siehe PraxisFormTemplateSeeder) -- ein eigenes Formular auf
     * demselben Kurs würde sonst mit dessen min_progress_percent in
     * Konflikt geraten (vgl. PraxisPruefungFormTest).
     */
    private function createSubmission(Tenant $tenant, User $learner, int $practiceAppointmentCount = 0): PraxisFormSubmission
    {
        $course = $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-EXAM-APPT-'.uniqid(),
                'name' => 'Test-Kurs für Prüfungstermine',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            return $course;
        });

        $this->grantEntitlement($tenant, $learner, $course);

        $template = $this->onAdmin(function () use ($course, $practiceAppointmentCount) {
            $template = PraxisFormTemplate::create([
                'title' => 'E2E Termin-Formular',
                'min_progress_percent' => 0,
                'course_ids' => [$course->id],
                'fields' => [],
                'active' => true,
                'practice_appointment_count' => $practiceAppointmentCount,
            ]);
            $this->createdTemplateIds[] = $template->id;

            return $template;
        });

        return $this->onAdmin(fn () => PraxisFormSubmission::create([
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $learner->id,
            'praxis_form_template_id' => $template->id,
            'data' => [],
        ]));
    }

    private function confirmSlot(Tenant $tenant, User $admin, User $learner, PraxisFormSubmission $submission, string $examDate, int $slot = 0): PraxisExamAppointment
    {
        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => $examDate, 'slot' => $slot]
        );
        $appointment = $this->onAdmin(fn () => PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->where('slot', $slot)->latest('round')->first());
        $this->actingAsInTenant($learner, $tenant)->post(
            route('praxis-pruefung.exam-appointments.respond', [$submission->course_id, $appointment->id]),
            ['decision' => 'accepted']
        );

        return $appointment->fresh();
    }

    public function test_admin_can_propose_an_exam_date(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner);

        $response = $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2026-05-10 09:00']
        );

        $response->assertSessionHasNoErrors();
        $this->onAdmin(function () use ($submission) {
            $appointment = PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->first();
            $this->assertNotNull($appointment);
            $this->assertSame(1, $appointment->round);
            $this->assertSame('pending', $appointment->response);
            $this->assertSame('10.05.2026 09:00', $appointment->exam_date->format('d.m.Y H:i'));
        });
    }

    public function test_admin_cannot_propose_a_new_date_while_one_is_still_pending(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner);
        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2026-05-10 09:00']
        );

        $response = $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2026-05-12 09:00']
        );

        $response->assertSessionHasErrors('exam_date');
        $this->onAdmin(function () use ($submission) {
            $this->assertSame(1, PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->count());
        });
    }

    public function test_admin_cannot_propose_for_a_submission_of_another_tenant(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $adminB = $this->createTenantUser($tenantB, 'owner');
        $learnerA = $this->createTenantUser($tenantA, 'learner');
        $submission = $this->createSubmission($tenantA, $learnerA);

        $response = $this->actingAsInTenant($adminB, $tenantB)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2026-05-10 09:00']
        );

        $response->assertNotFound();
    }

    public function test_learner_can_accept_a_proposed_exam_date(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner);
        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2026-05-10 09:00']
        );
        $appointment = $this->onAdmin(fn () => PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->first());

        $response = $this->actingAsInTenant($learner, $tenant)->post(
            route('praxis-pruefung.exam-appointments.respond', [$submission->course_id, $appointment->id]),
            ['decision' => 'accepted']
        );

        $response->assertSessionHasNoErrors();
        $this->onAdmin(function () use ($submission, $appointment) {
            $this->assertSame('accepted', $appointment->fresh()->response);
            $this->assertNotNull($submission->fresh()->confirmed_exam_date);
            $this->assertSame(
                $appointment->exam_date->format('Y-m-d H:i'),
                $submission->fresh()->confirmed_exam_date->format('Y-m-d H:i')
            );
        });
    }

    public function test_learner_can_decline_with_a_comment_and_admin_can_then_propose_a_second_round(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner);
        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2026-05-10 09:00']
        );
        $firstAppointment = $this->onAdmin(fn () => PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->first());

        $declineResponse = $this->actingAsInTenant($learner, $tenant)->post(
            route('praxis-pruefung.exam-appointments.respond', [$submission->course_id, $firstAppointment->id]),
            ['decision' => 'declined', 'comment' => 'Passt leider nicht, lieber nachmittags.']
        );

        $declineResponse->assertSessionHasNoErrors();
        $this->onAdmin(function () use ($submission, $firstAppointment) {
            $this->assertSame('declined', $firstAppointment->fresh()->response);
            $this->assertSame('Passt leider nicht, lieber nachmittags.', $firstAppointment->fresh()->response_comment);
            $this->assertNull($submission->fresh()->confirmed_exam_date);
        });

        $secondProposal = $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2026-05-17 09:00']
        );

        $secondProposal->assertSessionHasNoErrors();
        $this->onAdmin(function () use ($submission) {
            $this->assertSame(1, PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->where('round', 2)->count());
            $this->assertSame(2, PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->count());
        });
    }

    public function test_learner_cannot_respond_to_another_learners_appointment(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $otherLearner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner);
        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2026-05-10 09:00']
        );
        $appointment = $this->onAdmin(fn () => PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->first());

        $response = $this->actingAsInTenant($otherLearner, $tenant)->post(
            route('praxis-pruefung.exam-appointments.respond', [$submission->course_id, $appointment->id]),
            ['decision' => 'accepted']
        );

        $response->assertNotFound();
    }

    public function test_detail_page_shows_the_confirmed_exam_date(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner);
        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2026-05-10 09:00']
        );
        $appointment = $this->onAdmin(fn () => PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->first());
        $this->actingAsInTenant($learner, $tenant)->post(
            route('praxis-pruefung.exam-appointments.respond', [$submission->course_id, $appointment->id]),
            ['decision' => 'accepted']
        );

        $response = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");

        $response->assertOk();
        $response->assertSee('Prüfungstermin');
        $response->assertSee('10.05.2026 09:00');
    }

    public function test_learner_page_shows_the_pending_proposal_with_response_buttons(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner);
        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2026-05-10 09:00']
        );

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $submission->course_id));

        $response->assertOk();
        $response->assertSee('Vorschlag für deinen Prüfungstermin');
        $response->assertSee('10.05.2026 09:00');
        $response->assertSee('Passt mir');
        $response->assertSee('Passt mir nicht');
    }

    public function test_admin_cannot_propose_a_practice_appointment_before_the_exam_date_is_confirmed(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner, practiceAppointmentCount: 2);

        $response = $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2026-06-01 09:00', 'slot' => 1]
        );

        $response->assertSessionHasErrors('exam_date');
        $this->onAdmin(function () use ($submission) {
            $this->assertSame(0, PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->where('slot', 1)->count());
        });
    }

    public function test_admin_can_propose_and_confirm_multiple_independent_practice_appointments_after_the_exam_date(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner, practiceAppointmentCount: 2);
        $this->confirmSlot($tenant, $admin, $learner, $submission, '2026-06-01 09:00', slot: 0);

        $firstPractice = $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2026-06-05 10:00', 'slot' => 1]
        );
        $secondPractice = $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2026-06-06 10:00', 'slot' => 2]
        );

        $firstPractice->assertSessionHasNoErrors();
        $secondPractice->assertSessionHasNoErrors();
        $this->onAdmin(function () use ($submission) {
            $this->assertSame(1, PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->where('slot', 1)->count());
            $this->assertSame(1, PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->where('slot', 2)->count());
        });

        $appointment1 = $this->onAdmin(fn () => PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->where('slot', 1)->first());
        $this->actingAsInTenant($learner, $tenant)->post(
            route('praxis-pruefung.exam-appointments.respond', [$submission->course_id, $appointment1->id]),
            ['decision' => 'accepted']
        );

        $this->onAdmin(function () use ($submission) {
            $fresh = $submission->fresh();
            $this->assertTrue($fresh->isPracticeAppointmentConfirmed(1));
            $this->assertFalse($fresh->isPracticeAppointmentConfirmed(2));
        });
    }

    public function test_admin_cannot_propose_a_practice_slot_beyond_the_configured_count(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner, practiceAppointmentCount: 1);
        $this->confirmSlot($tenant, $admin, $learner, $submission, '2026-06-01 09:00', slot: 0);

        $response = $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2026-06-10 09:00', 'slot' => 2]
        );

        $response->assertSessionHasErrors('exam_date');
        $this->onAdmin(function () use ($submission) {
            $this->assertSame(0, PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->where('slot', 2)->count());
        });
    }

    public function test_a_declined_practice_appointment_allows_a_next_round_independent_of_other_slots(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner, practiceAppointmentCount: 2);
        $this->confirmSlot($tenant, $admin, $learner, $submission, '2026-06-01 09:00', slot: 0);

        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2026-06-05 10:00', 'slot' => 1]
        );
        $appointment1Round1 = $this->onAdmin(fn () => PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->where('slot', 1)->first());
        $this->actingAsInTenant($learner, $tenant)->post(
            route('praxis-pruefung.exam-appointments.respond', [$submission->course_id, $appointment1Round1->id]),
            ['decision' => 'declined', 'comment' => 'Lieber eine Woche später.']
        );

        $secondRound = $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2026-06-12 10:00', 'slot' => 1]
        );

        $secondRound->assertSessionHasNoErrors();
        $this->onAdmin(function () use ($submission) {
            $this->assertSame(2, PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->where('slot', 1)->count());
            $this->assertSame(1, PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->where('slot', 1)->where('round', 2)->count());
        });
    }

    public function test_detail_page_shows_practice_appointment_slots_once_exam_date_is_confirmed(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner, practiceAppointmentCount: 2);

        $before = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");
        $before->assertOk();
        $before->assertSee('Wird verfügbar, sobald der Prüfungstermin fixiert ist.');

        $this->confirmSlot($tenant, $admin, $learner, $submission, '2026-06-01 09:00', slot: 0);

        $after = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");
        $after->assertOk();
        $after->assertSee('Praxistermin 1 von 2');
        $after->assertSee('Praxistermin 2 von 2');
        $after->assertDontSee('Wird verfügbar, sobald der Prüfungstermin fixiert ist.');
    }

    public function test_learner_page_shows_practice_appointment_slots_only_after_exam_date_is_confirmed(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner, practiceAppointmentCount: 1);

        $before = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $submission->course_id));
        $before->assertOk();
        $before->assertDontSee('Praxistermin');

        $this->confirmSlot($tenant, $admin, $learner, $submission, '2026-06-01 09:00', slot: 0);

        $after = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $submission->course_id));
        $after->assertOk();
        $after->assertSee('Deine Bootsschule schlägt dir in Kürze deinen Praxistermin vor.');
    }
}
