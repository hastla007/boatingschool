<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\PraxisExamAppointment;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PraxisExamAppointmentService;
use RuntimeException;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Terminvorschlagsrunden zu einer Praxis-Anmeldung (siehe
 * PraxisExamAppointmentService): die Bootsschule schlägt einen Termin vor
 * (auf der Teilnehmer-Detailseite), der Lerner nimmt an oder lehnt ab --
 * bei Ablehnung folgt eine neue Runde, bis ein Termin angenommen und
 * damit fixiert wird. Sobald der Prüfungstermin (slot 0) fixiert ist,
 * kann die Bootsschule beliebig viele zusätzliche, frei benannte und
 * terminierte Termine anlegen (slot 1, 2, ...) -- keine feste Anzahl,
 * keine feste Bezeichnung.
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
    private function createSubmission(Tenant $tenant, User $learner): PraxisFormSubmission
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

        $template = $this->onAdmin(function () use ($course) {
            $template = PraxisFormTemplate::create([
                'title' => 'E2E Termin-Formular',
                'min_progress_percent' => 0,
                'course_ids' => [$course->id],
                'fields' => [],
                'active' => true,
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

    /** Schlägt den Prüfungstermin (slot 0) vor und lässt ihn vom Lerner annehmen. */
    private function confirmExamDate(Tenant $tenant, User $admin, User $learner, PraxisFormSubmission $submission, string $examDate): PraxisExamAppointment
    {
        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => $examDate, 'slot' => 0]
        );
        $appointment = $this->onAdmin(fn () => PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->where('slot', 0)->first());
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
            ['exam_date' => '2027-05-10 09:00', 'slot' => 0]
        );

        $response->assertSessionHasNoErrors();
        $this->onAdmin(function () use ($submission) {
            $appointment = PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->where('slot', 0)->first();
            $this->assertNotNull($appointment);
            $this->assertSame(1, $appointment->round);
            $this->assertSame('pending', $appointment->response);
            $this->assertSame('Prüfungstermin', $appointment->label);
            $this->assertNull($appointment->ends_at);
            // Bug-Review UTC: '2027-05-10 09:00' wird vom Admin in deutscher
            // Ortszeit eingegeben (datetime-local-Feld) und als solche
            // interpretiert -- intern gespeichert/abgefragt wird UTC, im
            // Mai (CEST, +2h) also 07:00.
            $this->assertSame('10.05.2027 07:00', $appointment->exam_date->format('d.m.Y H:i'));
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
            ['exam_date' => '2027-05-10 09:00', 'slot' => 0]
        );

        $response = $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2027-05-12 09:00', 'slot' => 0]
        );

        $response->assertSessionHasErrors('exam_date');
        $this->onAdmin(function () use ($submission) {
            $this->assertSame(1, PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->where('slot', 0)->count());
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
            ['exam_date' => '2027-05-10 09:00', 'slot' => 0]
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
            ['exam_date' => '2027-05-10 09:00', 'slot' => 0]
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
            ['exam_date' => '2027-05-10 09:00', 'slot' => 0]
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

        $secondRound = $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2027-05-17 09:00', 'slot' => 0]
        );

        $secondRound->assertSessionHasNoErrors();
        $this->onAdmin(function () use ($submission) {
            $this->assertSame(2, PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->where('slot', 0)->count());
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
            ['exam_date' => '2027-05-10 09:00', 'slot' => 0]
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
            ['exam_date' => '2027-05-10 09:00', 'slot' => 0]
        );
        $appointment = $this->onAdmin(fn () => PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->first());
        $this->actingAsInTenant($learner, $tenant)->post(
            route('praxis-pruefung.exam-appointments.respond', [$submission->course_id, $appointment->id]),
            ['decision' => 'accepted']
        );

        $response = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");

        $response->assertOk();
        $response->assertSee('Prüfungstermin');
        // Bug-Review UTC: '09:00' wird vom Admin als deutsche Ortszeit
        // eingegeben (siehe PraxisExamAppointmentController) und auch
        // wieder als solche angezeigt (GermanTime, Bug-Review B30) -- der
        // Rundweg liefert deshalb wieder exakt die eingegebene Uhrzeit.
        $response->assertSee('10.05.2027 09:00');
    }

    public function test_learner_page_shows_the_pending_proposal_with_response_buttons(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner);
        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2027-05-10 09:00', 'slot' => 0]
        );

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $submission->course_id));

        $response->assertOk();
        $response->assertSee('Vorschlag für deinen Prüfungstermin');
        // Bug-Review UTC: siehe test_detail_page_shows_the_confirmed_exam_date.
        $response->assertSee('10.05.2027 09:00');
        $response->assertSee('Passt mir');
        $response->assertSee('Passt mir nicht');
    }

    public function test_admin_cannot_create_a_new_appointment_before_the_exam_date_is_confirmed(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner);

        $response = $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2027-06-01 09:00', 'label' => 'Kleine Praxisstunde (1h)']
        );

        $response->assertSessionHasErrors('exam_date');
        $this->onAdmin(function () use ($submission) {
            $this->assertSame(0, PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->where('slot', '>', 0)->count());
        });
    }

    public function test_admin_must_provide_a_label_for_a_new_appointment(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner);
        $this->confirmExamDate($tenant, $admin, $learner, $submission, '2027-06-01 09:00');

        $response = $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2027-06-05 16:00']
        );

        $response->assertSessionHasErrors('exam_date');
        $this->onAdmin(function () use ($submission) {
            $this->assertSame(0, PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->where('slot', '>', 0)->count());
        });
    }

    public function test_admin_can_freely_create_several_independently_labeled_and_timed_appointments(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner);
        $this->confirmExamDate($tenant, $admin, $learner, $submission, '2027-06-01 09:00');

        $first = $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2027-06-05 16:00', 'ends_at' => '2027-06-05 18:00', 'label' => 'Kleine Praxisstunde (2h)']
        );
        $second = $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2027-06-06 09:00', 'label' => 'Navigationsstunde']
        );
        $third = $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2027-06-07 10:00', 'ends_at' => '2027-06-07 14:00', 'label' => 'Große Praxisstunde (4h)']
        );

        $first->assertSessionHasNoErrors();
        $second->assertSessionHasNoErrors();
        $third->assertSessionHasNoErrors();
        $this->onAdmin(function () use ($submission) {
            $appointments = PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)
                ->where('slot', '>', 0)->orderBy('slot')->get();
            $this->assertCount(3, $appointments);
            $this->assertSame('Kleine Praxisstunde (2h)', $appointments[0]->label);
            // Bug-Review UTC: '18:00' wird als deutsche Ortszeit eingegeben,
            // intern also als UTC 16:00 gespeichert (CEST, +2h im Juni).
            $this->assertSame('16:00', $appointments[0]->ends_at->format('H:i'));
            $this->assertSame('Navigationsstunde', $appointments[1]->label);
            $this->assertNull($appointments[1]->ends_at);
            $this->assertSame('Große Praxisstunde (4h)', $appointments[2]->label);
            $this->assertSame([1, 2, 3], $appointments->pluck('slot')->all());
        });

        $firstAppointment = $this->onAdmin(fn () => PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->where('slot', 1)->first());
        $this->actingAsInTenant($learner, $tenant)->post(
            route('praxis-pruefung.exam-appointments.respond', [$submission->course_id, $firstAppointment->id]),
            ['decision' => 'accepted']
        );

        $this->onAdmin(function () use ($submission) {
            $fresh = $submission->fresh();
            $this->assertTrue($fresh->isPracticeAppointmentConfirmed(1));
            $this->assertFalse($fresh->isPracticeAppointmentConfirmed(2));
            $this->assertFalse($fresh->isPracticeAppointmentConfirmed(3));
        });
    }

    public function test_an_end_time_before_the_start_time_is_rejected(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner);
        $this->confirmExamDate($tenant, $admin, $learner, $submission, '2027-06-01 09:00');

        $response = $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2027-06-05 16:00', 'ends_at' => '2027-06-05 14:00', 'label' => 'Kleine Praxisstunde']
        );

        $response->assertSessionHasErrors('ends_at');
    }

    public function test_a_declined_appointment_allows_a_next_round_independent_of_other_appointments(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner);
        $this->confirmExamDate($tenant, $admin, $learner, $submission, '2027-06-01 09:00');

        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2027-06-05 16:00', 'label' => 'Kleine Praxisstunde']
        );
        $round1 = $this->onAdmin(fn () => PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->where('slot', 1)->first());
        $this->actingAsInTenant($learner, $tenant)->post(
            route('praxis-pruefung.exam-appointments.respond', [$submission->course_id, $round1->id]),
            ['decision' => 'declined', 'comment' => 'Lieber eine Woche später.']
        );

        $secondRound = $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2027-06-12 16:00', 'slot' => 1]
        );

        $secondRound->assertSessionHasNoErrors();
        $this->onAdmin(function () use ($submission) {
            $appointments = PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->where('slot', 1)->orderBy('round')->get();
            $this->assertCount(2, $appointments);
            $this->assertSame('Kleine Praxisstunde', $appointments[1]->label, 'Bezeichnung bleibt über Runden hinweg erhalten.');
        });
    }

    public function test_detail_page_shows_the_new_appointment_form_only_once_exam_date_is_confirmed(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner);

        $before = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");
        $before->assertOk();
        $before->assertSee('können frei angelegt werden, sobald der Prüfungstermin fixiert ist.');

        $this->confirmExamDate($tenant, $admin, $learner, $submission, '2027-06-01 09:00');

        $after = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");
        $after->assertOk();
        $after->assertSee('Weiteren Termin anlegen');
        $after->assertDontSee('können frei angelegt werden, sobald der Prüfungstermin fixiert ist.');
    }

    public function test_detail_page_shows_a_freely_labeled_appointment_with_its_time_range(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner);
        $this->confirmExamDate($tenant, $admin, $learner, $submission, '2027-06-01 09:00');

        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2027-06-05 16:00', 'ends_at' => '2027-06-05 18:00', 'label' => 'Kleine Praxisstunde (2h)']
        );

        $response = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");

        $response->assertOk();
        $response->assertSee('Kleine Praxisstunde (2h)');
        // Bug-Review UTC: '16:00'/'18:00' werden vom Admin als deutsche
        // Ortszeit eingegeben und auch wieder als solche angezeigt --
        // siehe test_detail_page_shows_the_confirmed_exam_date.
        $response->assertSee('05.06.2027 16:00');
        $response->assertSee('18:00');
    }

    public function test_learner_page_shows_the_freely_labeled_appointment_with_its_time_range(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner);
        $this->confirmExamDate($tenant, $admin, $learner, $submission, '2027-06-01 09:00');

        $before = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $submission->course_id));
        $before->assertOk();
        $before->assertDontSee('Navigationsstunde');

        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2027-06-06 16:00', 'ends_at' => '2027-06-06 18:00', 'label' => 'Navigationsstunde']
        );

        $after = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $submission->course_id));
        $after->assertOk();
        $after->assertSee('Navigationsstunde');
        // Bug-Review UTC: siehe test_detail_page_shows_a_freely_labeled_appointment_with_its_time_range.
        $after->assertSee('06.06.2027 16:00');
        $after->assertSee('18:00');
        $after->assertSee('Passt mir');
    }

    /**
     * Bug-Review F4: exam_date wurde bisher nur als gültiges Datum
     * validiert, nicht als zukünftiges -- ein Termin ließ sich damit auch
     * für einen bereits vergangenen Zeitpunkt vorschlagen.
     */
    public function test_admin_cannot_propose_an_exam_date_in_the_past(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner);

        $response = $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => now()->subDay()->format('Y-m-d H:i'), 'slot' => 0]
        );

        $response->assertSessionHasErrors('exam_date');
        $this->onAdmin(fn () => $this->assertDatabaseMissing('praxis_exam_appointment', ['praxis_form_submission_id' => $submission->id]));
    }

    /**
     * Bug-Review F4: ein Zusatztermin (Praxisstunde, Navigationsstunde o.
     * ä.) baut inhaltlich auf dem fixierten Prüfungstermin auf -- ohne
     * diese Prüfung ließ sich trotzdem ein Zusatztermin VOR dem
     * Prüfungstermin anlegen.
     */
    public function test_admin_cannot_create_an_additional_appointment_before_the_confirmed_exam_date(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner);
        $examDate = now()->addMonths(2);
        $this->confirmExamDate($tenant, $admin, $learner, $submission, $examDate->format('Y-m-d H:i'));

        $response = $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => $examDate->copy()->subWeek()->format('Y-m-d H:i'), 'label' => 'Kleine Praxisstunde']
        );

        $response->assertSessionHasErrors('exam_date');
        $this->onAdmin(function () use ($submission) {
            $this->assertSame(0, PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->where('slot', '>', 0)->count());
        });
    }

    /**
     * Bug-Review F4: ein eigener, noch offener Vorschlag ließ sich bisher
     * nicht zurückziehen -- einzige Möglichkeit war, auf die Rückmeldung
     * des Lerners zu warten.
     */
    public function test_admin_can_withdraw_a_pending_proposal_and_propose_a_new_one(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner);
        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => now()->addMonth()->format('Y-m-d H:i'), 'slot' => 0]
        );
        $appointment = $this->onAdmin(fn () => PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->first());

        $withdraw = $this->actingAsInTenant($admin, $tenant)->patch(
            route('admin.praxis-form-submissions.exam-appointments.withdraw', [$submission, $appointment])
        );

        $withdraw->assertRedirect();
        $this->onAdmin(function () use ($appointment) {
            $this->assertSame('withdrawn', $appointment->fresh()->response);
        });

        // Eine neue Runde ist sofort möglich, genau wie nach einer
        // echten Ablehnung durch den Lerner.
        $second = $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => now()->addMonths(2)->format('Y-m-d H:i'), 'slot' => 0]
        );
        $second->assertSessionHasNoErrors();
        $this->onAdmin(function () use ($submission) {
            $this->assertSame(2, PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->where('slot', 0)->count());
        });
    }

    /**
     * Bug-Review F4: ein bereits angenommener (fixierter) Termin ließ sich
     * nicht mehr absagen -- z. B. wenn der Prüfungsausschuss den Termin
     * doch nicht wahrnehmen kann.
     */
    public function test_admin_can_cancel_a_confirmed_exam_date_and_propose_a_new_one(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner);
        $appointment = $this->confirmExamDate($tenant, $admin, $learner, $submission, now()->addMonth()->format('Y-m-d H:i'));

        $cancel = $this->actingAsInTenant($admin, $tenant)->patch(
            route('admin.praxis-form-submissions.exam-appointments.cancel', [$submission, $appointment])
        );

        $cancel->assertRedirect();
        $this->onAdmin(function () use ($submission, $appointment) {
            $this->assertSame('cancelled', $appointment->fresh()->response);
            $this->assertNull($submission->fresh()->confirmed_exam_date);
        });

        // Ein neuer Prüfungstermin kann sofort vorgeschlagen werden.
        $second = $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => now()->addMonths(2)->format('Y-m-d H:i'), 'slot' => 0]
        );
        $second->assertSessionHasNoErrors();
    }

    /**
     * Bug-Review F4: ein bereits verstrichener Termin ließ sich bisher
     * trotzdem noch annehmen.
     */
    public function test_learner_cannot_accept_an_appointment_whose_date_has_already_passed(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner);
        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => now()->addDay()->format('Y-m-d H:i'), 'slot' => 0]
        );
        $appointment = $this->onAdmin(fn () => PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->first());

        $this->travelTo(now()->addDays(2));

        $response = $this->actingAsInTenant($learner, $tenant)->post(
            route('praxis-pruefung.exam-appointments.respond', [$submission->course_id, $appointment->id]),
            ['decision' => 'accepted']
        );

        $response->assertSessionHasErrors('decision');
        $this->onAdmin(function () use ($appointment, $submission) {
            $this->assertSame('pending', $appointment->fresh()->response);
            $this->assertNull($submission->fresh()->confirmed_exam_date);
        });

        $this->travelBack();
    }

    /**
     * Bug-Review F4: zwei Tabs/Geräte, die beide "Annehmen"/"Ablehnen" auf
     * denselben Terminvorschlag schicken, konnten den jeweils anderen
     * stillschweigend überschreiben (klassisches Read-Then-Write-Race) --
     * bei "angenommen" gefolgt von "abgelehnt" blieb dabei sogar
     * confirmed_exam_date gesetzt, während der Termin selbst als
     * abgelehnt markiert war. Zwei unabhängig geladene Kopien desselben
     * Termins (wie zwei Tabs, die beide VOR jeder Rückmeldung geöffnet
     * wurden) simulieren das Race.
     */
    public function test_concurrent_responses_to_the_same_appointment_do_not_corrupt_state(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner);
        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => now()->addMonth()->format('Y-m-d H:i'), 'slot' => 0]
        );

        $tabA = $this->onAdmin(fn () => PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->first());
        $tabB = $this->onAdmin(fn () => PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->first());

        app(PraxisExamAppointmentService::class)->respond($tabA, 'accepted', null);

        $this->expectException(RuntimeException::class);

        try {
            app(PraxisExamAppointmentService::class)->respond($tabB, 'declined', 'Doch nicht.');
        } finally {
            $this->onAdmin(function () use ($submission) {
                $fresh = PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->first();
                $this->assertSame('accepted', $fresh->response);
                $this->assertNotNull($submission->fresh()->confirmed_exam_date);
            });
        }
    }
}
