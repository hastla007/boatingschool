<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\PraxisExamAppointment;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Models\Product;
use App\Models\ProductPurchase;
use App\Models\Progress;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WillkommensboxOrder;
use PDO;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Doppelklick-Race): Doppelklick beim ersten Lern-Attempt, bei
 * der Willkommensbox-Bestellung und bei der Antwort auf einen Termin --
 * drei Stellen, an denen ein doppelt abgeschickter Request (zwei fast
 * gleichzeitige POSTs) zu HTTP 500 führen konnte, weil ein "existiert
 * schon?"-Check und das anschließende Anlegen der Zeile nicht atomar waren
 * (TOCTOU: Time-of-check to time-of-use). Siehe LearningService::
 * recordAttempt() und WillkommensboxController::store() für die Fixes.
 */
class DoubleSubmitRaceTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    private array $createdTemplateIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        $this->onAdmin(function () {
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->whereKey($id)->delete();
            }

            // Bug-Review (Testdaten bleiben weiter liegen): $createdTemplateIds
            // wurde in createAppointmentSubmission() befüllt, aber hier nie
            // ausgelesen -- praxis_form_template hat kein tenant_id (globale,
            // kursgebundene Vorlage), die Zeile überlebte das Löschen des
            // Test-Tenants und -Kurses deshalb dauerhaft.
            foreach ($this->createdTemplateIds as $id) {
                PraxisFormTemplate::where('id', $id)->delete();
            }
        });

        parent::tearDown();
    }

    /**
     * Der allererste Attempt zu einer Frage: Progress::lockForUpdate()->
     * firstOrNew() sperrt nur eine schon existierende Zeile -- für die
     * erste progress-Zeile zu einer Frage sperrt SELECT ... FOR UPDATE
     * nichts (TOCTOU). Ein echter Doppelklick (zwei nahezu gleichzeitige
     * Requests) ließ beide Transaktionen exists() === false sehen und
     * beide ein INSERT versuchen; das zweite verletzte den Primary Key
     * (tenant_id, user_id, question_id) mit einer unbehandelten
     * QueryException (HTTP 500).
     *
     * Um das ohne eine zweite Verbindung/Transaktion, die während des
     * Requests offen bleibt, nicht künstlich "unecht" zu testen (ein rein
     * sequenzieller zweiter Request würde die bereits committete erste
     * Zeile einfach vorfinden und den Fehler NICHT reproduzieren), wird
     * hier über einen echten Kindprozess (pcntl_fork) eine zweite,
     * absichtlich noch offene Transaktion erzeugt, die exakt die gleiche
     * progress-Zeile einfügt und erst während des eigentlichen Requests
     * committet -- genau das Zeitfenster, das ein Doppelklick erzeugt.
     */
    public function test_double_clicking_the_first_attempt_at_a_question_does_not_cause_http_500(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix-Erweiterung nicht verfügbar.');
        }

        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('SBF_BASIS');
        [$question, $revision] = $this->anyPublishedQuestionIn($module);
        $correctAnswer = $revision->answers->firstWhere('is_correct', true);

        $adminConfig = config('database.connections.pgsql_admin');

        $pid = pcntl_fork();

        if ($pid === 0) {
            // Kindprozess: eigene, frische PDO-Verbindung (nicht die vom
            // Elternprozess bereits geöffnete) -- legt dieselbe
            // progress-Zeile an und hält die Transaktion absichtlich kurz
            // offen, bevor sie committet wird.
            try {
                $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $adminConfig['host'], $adminConfig['port'], $adminConfig['database']);
                $pdo = new PDO($dsn, $adminConfig['username'], $adminConfig['password']);
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO progress (tenant_id, user_id, question_id) VALUES (:t, :u, :q)')
                    ->execute(['t' => $tenant->id, 'u' => $learner->id, 'q' => $question->id]);
                $pdo->query('SELECT pg_sleep(0.4)');
                $pdo->commit();
            } finally {
                // Hartes Beenden ohne PHP-Shutdown-Handler (Laravel/PHPUnit
                // liefen im Elternprozess weiter -- doppelt ausgeführte
                // Shutdown-Handler im Kind würden die Testausgabe verdoppeln).
                posix_kill(posix_getpid(), SIGKILL);
            }
        }

        // Dem Kindprozess Zeit geben, seine Transaktion zu öffnen und die
        // Zeile (noch uncommittet) einzufügen, bevor der eigentliche
        // Request losläuft und genau in dieses Zeitfenster trifft.
        usleep(150_000);

        $response = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/learn/attempts", [
            'revision_id' => $revision->id,
            'answer_id' => $correctAnswer->id,
            'mode' => 'smarttrainer',
        ]);

        pcntl_waitpid($pid, $status);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->asTenant($tenant, function () use ($tenant, $learner, $question) {
            $this->assertSame(
                1,
                Progress::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->where('question_id', $question->id)->count()
            );
        });
    }

    /**
     * Die Willkommensbox-Bestellung: der exists()-Check in
     * WillkommensboxController::store() und das anschließende create()
     * sind nicht atomar. Statt eine zweite, parallel offene Transaktion zu
     * benötigen, reicht hier ein Eloquent-Event-Hook auf
     * WillkommensboxOrder::creating(), der exakt in dem Moment, in dem der
     * eigentliche Request sein create() ausführt, eine "von einem anderen
     * Request gewonnene" Zeile unterschiebt -- das exakte Resultat, das
     * ein Doppelklick erzeugt, ohne echte Nebenläufigkeit zu benötigen.
     */
    public function test_double_clicking_the_willkommensbox_order_does_not_cause_http_500(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $product = $this->onAdmin(fn () => Product::where('code', 'LERNPLATTFORM-WB')->firstOrFail());
        $this->onAdmin(fn () => ProductPurchase::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'product_id' => $product->id,
            'source_type' => 'coupon',
            'source_reference' => 'E2E-RACE-CODE',
        ]));

        // flushEventListeners() am Ende ist Pflicht: Eloquent-Event-Listener
        // sind klassenweit und würden sonst in jedem weiteren Test dieses
        // PHPUnit-Prozesses an WillkommensboxOrder::create() hängen bleiben.
        WillkommensboxOrder::creating(function () use ($tenant, $learner) {
            $this->onAdmin(fn () => WillkommensboxOrder::withoutEvents(fn () => WillkommensboxOrder::create([
                'tenant_id' => $tenant->id,
                'user_id' => $learner->id,
                'street' => 'Gewinnerstraße 1',
                'postal_code' => '11111',
                'city' => 'Gewinnerstadt',
                'country' => 'Deutschland',
            ])));
        });

        try {
            $response = $this->actingAsInTenant($learner, $tenant)->post('/willkommensbox', [
                'street' => 'Verliererstraße 2',
                'postal_code' => '22222',
                'city' => 'Verliererstadt',
                'country' => 'Deutschland',
            ]);
        } finally {
            WillkommensboxOrder::flushEventListeners();
        }

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        $this->onAdmin(function () use ($tenant, $learner) {
            $this->assertSame(1, WillkommensboxOrder::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->count());
            $this->assertDatabaseHas('willkommensbox_order', ['tenant_id' => $tenant->id, 'user_id' => $learner->id, 'street' => 'Gewinnerstraße 1']);
        });
    }

    /**
     * Die Antwort auf einen Terminvorschlag ist bereits durch einen
     * pg_advisory_xact_lock + Zustandsprüfung in
     * PraxisExamAppointmentService::respond() abgesichert (Bug-Review F4);
     * der Controller fängt die dabei geworfene RuntimeException bereits
     * ab. Dieser Test bestätigt das auf HTTP-Ebene (nicht nur direkt am
     * Service wie der bestehende Test
     * test_concurrent_responses_to_the_same_appointment_do_not_corrupt_state):
     * ein doppelt abgeschickter Request auf /respond endet als normale,
     * mit einer Fehlermeldung zurückgeleitete Antwort, nicht als HTTP 500.
     */
    public function test_double_clicking_a_response_to_an_exam_appointment_does_not_cause_http_500(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createAppointmentSubmission($tenant, $learner);

        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => now()->addMonth()->format('Y-m-d H:i'), 'slot' => 0]
        );
        $appointment = $this->onAdmin(fn () => PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->first());

        $respondUrl = route('praxis-pruefung.exam-appointments.respond', [$submission->course_id, $appointment->id]);

        $first = $this->actingAsInTenant($learner, $tenant)->post($respondUrl, ['decision' => 'accepted']);
        $first->assertSessionHasNoErrors();

        $second = $this->actingAsInTenant($learner, $tenant)->post($respondUrl, ['decision' => 'accepted']);
        $second->assertStatus(302);
        $this->assertNotSame(500, $second->getStatusCode());

        $this->onAdmin(function () use ($submission, $appointment) {
            $this->assertSame('accepted', $appointment->fresh()->response);
            $this->assertNotNull($submission->fresh()->confirmed_exam_date);
        });
    }

    private function createAppointmentSubmission(Tenant $tenant, User $learner): PraxisFormSubmission
    {
        $course = $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-RACE-APPT-'.uniqid(),
                'name' => 'Test-Kurs für Doppelklick-Termin',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            return $course;
        });

        $this->grantEntitlement($tenant, $learner, $course);

        $template = $this->onAdmin(function () use ($course) {
            $template = PraxisFormTemplate::create([
                'title' => 'E2E Doppelklick-Termin-Formular',
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
}
