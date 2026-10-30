<?php

namespace Tests\Feature;

use App\Models\CourseExamRegistration;
use App\Models\PraxisExamAppointment;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Support\ExamRegistration;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Umstellung auf Selbstanmeldung): stellt die Bootsschule einen
 * Kurs von "Bootsschule meldet an" auf "Teilnehmer meldet sich selbst an",
 * leitete das Termintool bisher JEDEN Teilnehmer auf "Prüfung buchen" um --
 * auch solche mit bereits abgeschickter Praxis-Anmeldung, die dadurch ihre
 * Anmeldung und vorgeschlagenen Termine nicht mehr sahen, während der
 * Admin weiter neue Termine vorschlagen konnte.
 */
class SelfRegistrationKeepsExistingPraxisSubmissionTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdTemplateIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        if ($this->createdTemplateIds !== []) {
            $this->onAdmin(fn () => PraxisFormTemplate::whereIn('id', $this->createdTemplateIds)->delete());
        }

        parent::tearDown();
    }

    public function test_learners_with_a_submission_keep_the_appointment_tool_after_switching_to_self_registration(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $submitted = $this->createTenantUser($tenant, 'learner');
        $newcomer = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC-UBI');
        $this->grantEntitlement($tenant, $submitted, $course);
        $this->grantEntitlement($tenant, $newcomer, $course);

        $submission = $this->onAdmin(function () use ($tenant, $course, $submitted) {
            $template = PraxisFormTemplate::create([
                'title' => 'Test Selbstanmeldung-Formular',
                'min_progress_percent' => 0,
                'course_ids' => [$course->id],
                'fields' => [],
                'active' => true,
            ]);
            $this->createdTemplateIds[] = $template->id;

            return PraxisFormSubmission::create([
                'tenant_id' => $tenant->id,
                'course_id' => $course->id,
                'user_id' => $submitted->id,
                'praxis_form_template_id' => $template->id,
                'data' => [],
            ]);
        });

        // Noch "Bootsschule meldet an": Terminvorschlag funktioniert.
        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => now()->addWeek()->format('Y-m-d\TH:i'), 'slot' => 0]
        )->assertSessionHasNoErrors();
        $this->assertSame(1, $this->onAdmin(fn () => PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->count()));

        $this->onAdmin(fn () => CourseExamRegistration::create(['tenant_id' => $tenant->id, 'course_id' => $course->id, 'mode' => ExamRegistration::SELF]));

        $this->actingAsInTenant($submitted, $tenant)->get("/courses/{$course->id}/praxis-pruefung")
            ->assertOk()
            ->assertViewHas('state', 'submitted')
            ->assertViewHas('submission', fn ($s) => $s->id === $submission->id && $s->examAppointments->count() === 1);

        $this->actingAsInTenant($newcomer, $tenant)->get("/courses/{$course->id}/praxis-pruefung")
            ->assertRedirect("/courses/{$course->id}/pruefung-buchen");

        // Kursseite: mit Anmeldung bleibt die Kachel zum Termintool, ohne
        // Anmeldung erscheint "Prüfung buchen".
        $this->actingAsInTenant($submitted, $tenant)->get("/courses/{$course->id}")
            ->assertOk()
            ->assertSee(route('praxis-pruefung.index', $course), false)
            ->assertSee('Deine Anmeldung und Termine')
            ->assertDontSee(route('exam-booking', $course), false);
        $this->actingAsInTenant($newcomer, $tenant)->get("/courses/{$course->id}")
            ->assertOk()
            ->assertSee(route('exam-booking', $course), false);

        // Neue Vorschläge lehnt der Admin-Bereich jetzt verständlich ab.
        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => now()->addWeeks(2)->format('Y-m-d\TH:i'), 'label' => 'Zusatztermin']
        )->assertSessionHasErrors('exam_date');
        $this->assertSame(1, $this->onAdmin(fn () => PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->count()));
    }
}
