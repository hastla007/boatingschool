<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\PraxisExamAppointment;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Models\Progress;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Zwei neue Dashboard-Kennzahlen (AdminDashboardController): Kacheln für
 * anstehende Praxistermine (7/30 Tage, nur bereits angenommene Vorschläge
 * zählen) und ein Phasen-Chart, das zeigt, wie viele Teilnehmer gerade in
 * welcher Phase (Lernen, Praxis angemeldet, Prüfungstermin fixiert,
 * geprüft) stehen -- jeweils der am weitesten fortgeschrittene Status je
 * Lerner über all seine Kurse hinweg.
 */
class AdminDashboardMetricsTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdTemplateIds = [];

    private array $createdCourseIds = [];

    private array $createdRuleSetIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        $this->onAdmin(function () {
            foreach ($this->createdTemplateIds as $id) {
                PraxisFormTemplate::where('id', $id)->delete();
            }
            foreach ($this->createdRuleSetIds as $id) {
                ExamRuleSet::where('id', $id)->delete();
            }
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }
        });

        parent::tearDown();
    }

    private function createSubmission(Tenant $tenant, User $learner): PraxisFormSubmission
    {
        $course = $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-DASH-METRICS-'.uniqid(),
                'name' => 'Test-Kurs für Dashboard-Kennzahlen',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            return $course;
        });

        $this->grantEntitlement($tenant, $learner, $course);

        $template = $this->onAdmin(function () use ($course) {
            $template = PraxisFormTemplate::create([
                'title' => 'E2E Dashboard-Formular',
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

    /** Liest den Zahlenwert einer x-stat-tile anhand ihres Labels aus dem gerenderten HTML. */
    private function assertStatTileValue(TestResponse $response, string $label, string $expectedValue): void
    {
        preg_match(
            '/text-xl font-bold leading-tight[^>]*>([^<]*)<\/div>\s*<div class="text-xs text-slate-400 truncate">'.preg_quote($label, '/').'/',
            $response->getContent(),
            $matches
        );
        $this->assertNotEmpty($matches, "Stat-Kachel \"{$label}\" nicht gefunden.");
        $this->assertSame($expectedValue, trim($matches[1]));
    }

    /** Liest Prozentsatz + Anzahl einer Phasen-Legendenzeile anhand ihres Labels aus. */
    private function assertPhasePercent(TestResponse $response, string $label, int $count, int $percent): void
    {
        preg_match(
            '/font-medium">(\d+)%<\/div>\s*<div class="text-xs text-slate-400 truncate">'.preg_quote($label, '/').' \((\d+)\)/s',
            $response->getContent(),
            $matches
        );
        $this->assertNotEmpty($matches, "Phase \"{$label}\" nicht gefunden.");
        $this->assertSame((string) $percent, $matches[1]);
        $this->assertSame((string) $count, $matches[2]);
    }

    private function createAcceptedAppointment(Tenant $tenant, User $admin, PraxisFormSubmission $submission, \DateTimeInterface $examDate, int $slot = 0): PraxisExamAppointment
    {
        return $this->onAdmin(fn () => PraxisExamAppointment::create([
            'tenant_id' => $tenant->id,
            'praxis_form_submission_id' => $submission->id,
            'slot' => $slot,
            'label' => $slot === 0 ? 'Prüfungstermin' : 'Kleine Praxisstunde',
            'round' => 1,
            'exam_date' => $examDate,
            'proposed_by_user_id' => $admin->id,
            'response' => 'accepted',
            'responded_at' => now(),
        ]));
    }

    public function test_dashboard_counts_accepted_appointments_within_seven_and_thirty_days(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner);

        $this->createAcceptedAppointment($tenant, $admin, $submission, now()->addDays(3), 0);
        $this->createAcceptedAppointment($tenant, $admin, $submission, now()->addDays(20), 1);
        $this->createAcceptedAppointment($tenant, $admin, $submission, now()->addDays(45), 2);

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard'));

        $response->assertOk();
        $this->assertStatTileValue($response, 'Praxistermine (7 Tage)', '1');
        $this->assertStatTileValue($response, 'Praxistermine (30 Tage)', '2');
    }

    public function test_dashboard_does_not_count_pending_or_past_appointments(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createSubmission($tenant, $learner);

        // Noch offener Vorschlag -- zählt nicht, bis der Teilnehmer
        // zugesagt hat.
        $this->onAdmin(fn () => PraxisExamAppointment::create([
            'tenant_id' => $tenant->id,
            'praxis_form_submission_id' => $submission->id,
            'slot' => 0,
            'label' => 'Prüfungstermin',
            'round' => 1,
            'exam_date' => now()->addDays(3),
            'proposed_by_user_id' => $admin->id,
            'response' => 'pending',
        ]));
        // Liegt bereits in der Vergangenheit -- anderer Slot, damit es
        // nicht mit dem offenen Vorschlag oben (slot 0, Runde 1) kollidiert.
        $this->createAcceptedAppointment($tenant, $admin, $submission, now()->subDays(1), 1);

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard'));

        $response->assertOk();
        $this->assertStatTileValue($response, 'Praxistermine (7 Tage)', '0');
        $this->assertStatTileValue($response, 'Praxistermine (30 Tage)', '0');
    }

    public function test_dashboard_shows_learner_phase_distribution(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        // Nicht gestartet -- kein aktiver Kurszugang.
        $this->createTenantUser($tenant, 'learner', ['display_name' => 'Noch nicht gestartet']);

        // Lernen -- aktiver Kurszugang, aber noch keine Praxis-Anmeldung.
        $learning = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Lernt gerade']);
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learning, $course);

        // Praxis angemeldet -- Anmeldung da, aber kein fixierter Termin.
        $registered = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Praxis angemeldet']);
        $this->createSubmission($tenant, $registered);

        // Prüfungstermin fixiert.
        $confirmed = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Termin fixiert']);
        $confirmedSubmission = $this->createSubmission($tenant, $confirmed);
        $this->onAdmin(fn () => $confirmedSubmission->update(['confirmed_exam_date' => now()->addDays(5)]));

        // Geprüft -- bestandene theoretische Prüfung.
        $passed = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Geprüft']);
        $ruleSet = $this->onAdmin(function () use ($course) {
            $ruleSet = ExamRuleSet::create([
                'course_id' => $course->id,
                'version' => 'test-'.uniqid(),
                'time_limit_seconds' => 3600,
                'passing_rule' => ['min_correct_ratio' => 0.75],
                'status' => 'published',
            ]);
            $this->createdRuleSetIds[] = $ruleSet->id;

            return $ruleSet;
        });
        // Bug-Review N14: "Geprüft" darf nur ein bestandener amtlicher
        // Prüfungsbogen (paper_id gesetzt) zählen, kein zufällig
        // zusammengestelltes Übungsbogen (siehe ExamController::start()
        // vs. startPaper()) -- deshalb hier ein echter ExamPaper des Kurses.
        $paper = $this->onAdmin(fn () => \App\Models\ExamPaper::where('course_id', $course->id)->first());
        $this->onAdmin(fn () => ExamSession::create([
            'tenant_id' => $tenant->id,
            'user_id' => $passed->id,
            'course_id' => $course->id,
            'rule_set_id' => $ruleSet->id,
            'paper_id' => $paper->id,
            'status' => 'evaluated',
            'passed' => true,
            'started_at' => now()->subHour(),
            'submitted_at' => now(),
            'score' => 95,
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Teilnehmer je Phase');
        // Je 1 von 5 Lernern (20%) in jeder der fünf Phasen.
        $this->assertPhasePercent($response, 'Nicht gestartet', 1, 20);
        $this->assertPhasePercent($response, 'Lernen', 1, 20);
        $this->assertPhasePercent($response, 'Praxis angemeldet', 1, 20);
        $this->assertPhasePercent($response, 'Prüfungstermin fixiert', 1, 20);
        $this->assertPhasePercent($response, 'Geprüft', 1, 20);
    }

    /**
     * Bug-Review N14: ExamController::start() legt ohne paper_id eine
     * zufällig zusammengestellte Übungsprüfung an -- nur ein bestandener
     * amtlicher Prüfungsbogen (paper_id gesetzt, über startPaper()) darf
     * als "Geprüft" zählen.
     */
    public function test_dashboard_does_not_count_a_passed_practice_exam_as_geprueft(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');

        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $learner, $course);

        $ruleSet = $this->onAdmin(function () use ($course) {
            $ruleSet = ExamRuleSet::create([
                'course_id' => $course->id,
                'version' => 'test-'.uniqid(),
                'time_limit_seconds' => 3600,
                'passing_rule' => ['min_correct_ratio' => 0.75],
                'status' => 'published',
            ]);
            $this->createdRuleSetIds[] = $ruleSet->id;

            return $ruleSet;
        });
        // Bewusst ohne paper_id -- entspricht einer über ExamController::
        // start() zufällig zusammengestellten Übungsprüfung.
        $this->onAdmin(fn () => ExamSession::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'course_id' => $course->id,
            'rule_set_id' => $ruleSet->id,
            'status' => 'evaluated',
            'passed' => true,
            'started_at' => now()->subHour(),
            'submitted_at' => now(),
            'score' => 95,
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard'));

        $response->assertOk();
        // Der einzige Lerner hat einen aktiven Kurszugang und keine
        // bestandene amtliche Prüfung -> bleibt in "Lernen", nicht "Geprüft".
        $this->assertPhasePercent($response, 'Lernen', 1, 100);
        $this->assertPhasePercent($response, 'Geprüft', 0, 0);
    }

    public function test_dashboard_shows_progress_distribution_for_learning_phase_learners(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');

        // 0% -- noch keine einzige Frage gefestigt.
        $beginner = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Anfänger']);
        $this->grantEntitlement($tenant, $beginner, $course);

        // 100% -- alle Fragen des Kurses gefestigt.
        $expert = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Fast fertig']);
        $this->grantEntitlement($tenant, $expert, $course);
        $this->onAdmin(function () use ($tenant, $expert, $course) {
            $questionIds = $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id')->unique();
            foreach ($questionIds as $questionId) {
                Progress::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'user_id' => $expert->id, 'question_id' => $questionId],
                    ['learning_state' => 'gefestigt', 'mastery_score' => 1, 'attempt_count' => 1, 'correct_count' => 1]
                );
            }
        });

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Kursfortschritt in der Lernphase');
        $response->assertSee('0–20%: 1 Teilnehmer (50%)');
        $response->assertSee('81–100%: 1 Teilnehmer (50%)');
    }

    /**
     * Bug-Review N14: die Teilnehmerliste lud bislang jedes Mitglied der
     * Bootsschule auf einen Schlag, ohne Seitenaufteilung. Die "Coupon
     * zuweisen"-Auswahl im Coupon-Tab braucht dagegen wirklich alle
     * Teilnehmer und darf durch die Seitenaufteilung nicht einbrechen.
     */
    public function test_participants_tab_paginates_while_the_coupon_assign_dropdown_still_lists_everyone(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');
        $learners = [];
        for ($i = 1; $i <= 30; $i++) {
            $learners[] = $this->createTenantUser($tenant, 'learner', ['display_name' => "Teilnehmer {$i}"]);
        }
        $code = 'E2E-N14-PAGE-'.uniqid();
        $this->onAdmin(fn () => \App\Models\Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard'));

        $response->assertOk();
        // Nur die erste Seite (25) wird in der Teilnehmerliste angezeigt...
        $response->assertSee('<span class="text-slate-700 dark:text-slate-200">Teilnehmer 1</span>', false);
        $response->assertDontSee('<span class="text-slate-700 dark:text-slate-200">Teilnehmer 30</span>', false);
        // ...aber im Coupon-Zuweisen-Dropdown stehen weiterhin alle 30 zur Auswahl.
        $response->assertSee('>Teilnehmer 30</option>', false);

        $this->onAdmin(fn () => \App\Models\Coupon::where('code', $code)->delete());
    }

    public function test_dashboard_excludes_non_learning_phase_learners_from_progress_chart(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        // Hat bereits eine Praxis-Anmeldung -- zählt zur Phase "Praxis
        // angemeldet", nicht zu "Lernen", taucht also hier nicht auf.
        $registered = $this->createTenantUser($tenant, 'learner');
        $this->createSubmission($tenant, $registered);

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Aktuell keine Teilnehmer in der Lernphase.');
    }

    public function test_dashboard_counts_evaluated_exams_within_seven_days_separately_from_thirty(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $ruleSet = $this->onAdmin(function () use ($course) {
            $ruleSet = ExamRuleSet::create([
                'course_id' => $course->id,
                'version' => 'test-'.uniqid(),
                'time_limit_seconds' => 3600,
                'passing_rule' => ['min_correct_ratio' => 0.75],
                'status' => 'published',
            ]);
            $this->createdRuleSetIds[] = $ruleSet->id;

            return $ruleSet;
        });

        // Vor 2 Tagen ausgewertet -- zählt zu beiden Kacheln.
        $this->onAdmin(function () use ($tenant, $learner, $course, $ruleSet) {
            $session = ExamSession::create([
                'tenant_id' => $tenant->id, 'user_id' => $learner->id, 'course_id' => $course->id,
                'rule_set_id' => $ruleSet->id, 'status' => 'evaluated', 'passed' => true,
                'started_at' => now()->subDays(2), 'submitted_at' => now()->subDays(2), 'score' => 90,
            ]);
            $session->forceFill(['created_at' => now()->subDays(2)])->save();
        });
        // Vor 20 Tagen ausgewertet -- nur in der 30-Tage-Kachel.
        $this->onAdmin(function () use ($tenant, $learner, $course, $ruleSet) {
            $session = ExamSession::create([
                'tenant_id' => $tenant->id, 'user_id' => $learner->id, 'course_id' => $course->id,
                'rule_set_id' => $ruleSet->id, 'status' => 'evaluated', 'passed' => false,
                'started_at' => now()->subDays(20), 'submitted_at' => now()->subDays(20), 'score' => 40,
            ]);
            $session->forceFill(['created_at' => now()->subDays(20)])->save();
        });

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard'));

        $response->assertOk();
        $this->assertStatTileValue($response, 'Prüfungen (7 Tage)', '1');
        $this->assertStatTileValue($response, 'Prüfungen (30 Tage)', '2');
    }
}
