<?php

namespace Tests\Feature;

use App\Models\AddonTemplate;
use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\Module;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Models\Progress;
use App\Models\Pruefungsausschuss;
use App\Models\TenantAddon;
use App\Models\TenantCourseLocation;
use App\Notifications\PraxisFormSubmissionReceived;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * "Praxis & Prüfung" zeigt je nach zugewiesenem Praxisformular (siehe
 * PraxisFormTemplate) entweder den Platzhalter (kein Formular zugewiesen),
 * eine Hürden-Meldung (Kursfortschritt unter min_progress_percent), das
 * dynamische Formular, oder nach dem Absenden eine Bestätigung.
 */
class PraxisPruefungFormTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdTemplateIds = [];

    private array $createdCourseIds = [];

    private array $createdModuleIds = [];

    private array $createdQuestionIds = [];

    /**
     * Bug-Review Testisolation: zwei Tests legen hier über AddonTemplate::
     * create() eine echte, global geteilte Vorlage an -- $createdTemplateIds
     * oben trackt ausschließlich PraxisFormTemplate (ein anderes Modell/
     * Tabelle), diese Zeilen blieben also dauerhaft stehen.
     */
    private array $createdAddonTemplateIds = [];

    /**
     * Bug-Review Testisolation: ein Test legt hier einen echten
     * Pruefungsausschuss an, ohne ihn je wieder zu löschen.
     */
    private array $createdPruefungsausschussIds = [];

    protected function tearDown(): void
    {
        // Tenants zuerst aufräumen (kaskadiert praxis_form_submission und
        // tenant_course_location weg), erst danach die global geteilten
        // Formular-/Addon-Vorlagen und Prüfungsausschüsse löschen -- sonst
        // schlägt das wegen der FK auf noch existierende Einreichungen bzw.
        // Orte fehl.
        $this->cleanUpCreatedTenantsAndUsers();

        $this->onAdmin(function () {
            foreach ($this->createdTemplateIds as $id) {
                PraxisFormTemplate::where('id', $id)->delete();
            }
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }
            foreach ($this->createdModuleIds as $id) {
                Module::where('id', $id)->delete();
            }
            foreach ($this->createdQuestionIds as $id) {
                ContentQuestion::where('id', $id)->delete();
            }
            foreach ($this->createdAddonTemplateIds as $id) {
                AddonTemplate::where('id', $id)->delete();
            }
            foreach ($this->createdPruefungsausschussIds as $id) {
                Pruefungsausschuss::where('id', $id)->delete();
            }
        });

        parent::tearDown();
    }

    /**
     * Isolierter Test-Kurs mit eigenen Fragen statt eines echten
     * Katalogkurses -- die echten Kurse sind inzwischen alle einem Demo-
     * Praxisformular zugewiesen (siehe PraxisFormTemplateSeeder), sodass
     * Tests, die selbst ein Formular zuweisen, sonst in
     * Exklusivitäts-Konflikte liefen.
     *
     * @return array{0: CourseDefinition, 1: array<int, string>}
     */
    private function makeIsolatedCourseWithQuestions(int $count): array
    {
        return $this->onAdmin(function () use ($count) {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-PPF-'.uniqid(),
                'name' => 'Test-Kurs für Praxis & Prüfung',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $module = Module::create([
                'code' => 'TEST-PPF-MODULE-'.uniqid(),
                'name' => 'Testmodul',
                'version' => '1',
                'status' => 'published',
            ]);
            $this->createdModuleIds[] = $module->id;
            $course->modules()->attach($module->id, ['sort_order' => 1, 'required' => true]);

            $questionIds = [];
            for ($i = 0; $i < $count; $i++) {
                $question = ContentQuestion::create([
                    'content_id' => 'TEST-PPF-Q-'.uniqid(),
                    'language' => 'de-DE',
                    'active' => true,
                ]);
                $this->createdQuestionIds[] = $question->id;
                $questionIds[] = $question->id;
                $module->questions()->attach($question->id, ['sort_order' => $i + 1, 'required' => true]);
            }

            return [$course, $questionIds];
        });
    }

    private function createTemplate(array $overrides = []): PraxisFormTemplate
    {
        return $this->onAdmin(function () use ($overrides) {
            $template = PraxisFormTemplate::create(array_merge([
                'title' => 'E2E Praxisformular',
                'min_progress_percent' => 50,
                'course_ids' => [],
                'fields' => [
                    ['key' => 'telefonnummer', 'label' => 'Telefonnummer', 'type' => 'text', 'required' => true, 'options' => [], 'help_text' => null],
                    ['key' => 'geschlecht', 'label' => 'Geschlecht', 'type' => 'radio', 'required' => true, 'options' => ['Männlich', 'Weiblich', 'Divers'], 'help_text' => null],
                    ['key' => 'tauglichkeit', 'label' => 'Ich habe bereits die Tauglichkeit', 'type' => 'checkbox', 'required' => false, 'options' => [], 'help_text' => null],
                ],
                'active' => true,
            ], $overrides));
            $this->createdTemplateIds[] = $template->id;

            return $template;
        });
    }

    private function masterAllQuestions($tenant, $learner, $course): void
    {
        $this->onAdmin(function () use ($tenant, $learner, $course) {
            $questionIds = $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id')->unique();

            foreach ($questionIds as $questionId) {
                Progress::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'user_id' => $learner->id, 'question_id' => $questionId],
                    ['learning_state' => 'gefestigt', 'mastery_score' => 1, 'attempt_count' => 1, 'correct_count' => 1]
                );
            }
        });
    }

    public function test_shows_placeholder_without_an_assigned_template(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        // Ohne zugewiesenes Formular gilt weiter die bootsschulweite
        // Branding-Schwelle (Standard 50%) -- hier auf 0 gesetzt, um nur
        // das "kein Formular zugewiesen"-Verhalten isoliert zu prüfen.
        $this->onAdmin(fn () => $tenant->branding->update(['exam_readiness_threshold_percent' => 0]));

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course));

        $response->assertOk();
        $response->assertSee('wird in Kürze verfügbar sein');
    }

    public function test_shows_the_threshold_gate_below_the_required_progress(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        $this->createTemplate(['course_ids' => [$course->id], 'min_progress_percent' => 50]);

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course));

        $response->assertOk();
        $response->assertSee('Du erfüllst noch nicht alle Voraussetzungen');
        $response->assertSee('50% Kursfortschritt');
    }

    public function test_shows_the_dynamic_form_once_the_threshold_is_reached(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        $this->masterAllQuestions($tenant, $learner, $course);
        $template = $this->createTemplate(['course_ids' => [$course->id], 'min_progress_percent' => 10]);

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course));

        $response->assertOk();
        $response->assertSee($template->title);
        $response->assertSee('Telefonnummer');
        $response->assertSee('Geschlecht');
    }

    public function test_shows_an_incomplete_profile_hint_instead_of_the_form_without_address_and_birthdate(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        // Kein completeRegistrationProfile() -- Profil bleibt unvollständig.
        $this->masterAllQuestions($tenant, $learner, $course);
        $this->createTemplate(['course_ids' => [$course->id], 'min_progress_percent' => 10]);

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course));

        $response->assertOk();
        $response->assertSee('Vervollständige zuerst dein Profil');
        $response->assertSee(route('profile.edit'), false);
        $response->assertDontSee('Telefonnummer');
    }

    public function test_incomplete_profile_hint_disappears_once_the_profile_is_completed(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->masterAllQuestions($tenant, $learner, $course);
        $this->createTemplate(['course_ids' => [$course->id], 'min_progress_percent' => 10]);

        $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course))
            ->assertSee('Vervollständige zuerst dein Profil');

        $this->completeRegistrationProfile($learner, $tenant);

        $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course))
            ->assertSee('Telefonnummer')
            ->assertDontSee('Vervollständige zuerst dein Profil');
    }

    public function test_submission_is_rejected_without_a_complete_profile(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->masterAllQuestions($tenant, $learner, $course);
        $this->createTemplate(['course_ids' => [$course->id], 'min_progress_percent' => 10]);

        $response = $this->actingAsInTenant($learner, $tenant)->post(route('praxis-pruefung.store', $course), []);

        $response->assertForbidden();
        $this->onAdmin(fn () => $this->assertDatabaseMissing('praxis_form_submission', [
            'tenant_id' => $tenant->id, 'user_id' => $learner->id, 'course_id' => $course->id,
        ]));
    }

    public function test_learner_can_submit_the_form_and_sees_a_confirmation(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        $this->masterAllQuestions($tenant, $learner, $course);
        $template = $this->createTemplate(['course_ids' => [$course->id], 'min_progress_percent' => 10]);

        $response = $this->actingAsInTenant($learner, $tenant)->post(route('praxis-pruefung.store', $course), [
            'telefonnummer' => '+49 170 1234567',
            'geschlecht' => 'Männlich',
            'tauglichkeit' => '1',
        ]);

        $response->assertRedirect(route('praxis-pruefung.index', $course));

        $this->onAdmin(function () use ($tenant, $learner, $course, $template) {
            $this->assertDatabaseHas('praxis_form_submission', [
                'tenant_id' => $tenant->id,
                'user_id' => $learner->id,
                'course_id' => $course->id,
                'praxis_form_template_id' => $template->id,
            ]);
        });

        $follow = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course));
        $follow->assertOk();
        $follow->assertSee('Deine Anmeldung ist eingegangen.');
        $follow->assertSee('+49 170 1234567');
        $follow->assertSee('Männlich');
    }

    /**
     * Bug-Review F5: im gesamten Ablauf von Praxis-Anmeldung, Terminen und
     * Willkommensbox wurde bisher keine Mail verschickt -- eine Bootsschule
     * bemerkte eine neue Praxis-Anmeldung nur, wenn jemand aktiv im
     * Admin-Bereich nachsah.
     */
    public function test_submitting_the_form_notifies_the_schools_admins_by_mail(): void
    {
        Notification::fake();

        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        $this->masterAllQuestions($tenant, $learner, $course);
        $this->createTemplate(['course_ids' => [$course->id], 'min_progress_percent' => 10]);

        $response = $this->actingAsInTenant($learner, $tenant)->post(route('praxis-pruefung.store', $course), [
            'telefonnummer' => '+49 170 1234567',
            'geschlecht' => 'Männlich',
            'tauglichkeit' => '1',
        ]);

        $response->assertRedirect(route('praxis-pruefung.index', $course));

        Notification::assertSentTo($owner, PraxisFormSubmissionReceived::class);
    }

    /**
     * Bug-Review N16: zwischen der Prüfung "noch nicht angemeldet?" und dem
     * eigentlichen INSERT lag kein Lock -- ein Doppelklick auf "Absenden"
     * konnte zwei Anmeldungen für denselben Kurs anlegen und verletzte dann
     * den Unique-Index (tenant_id, user_id, course_id), was als uncaught
     * QueryException in einem HTTP 500 endete.
     */
    public function test_double_submitting_the_form_does_not_create_a_duplicate_submission(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        $this->masterAllQuestions($tenant, $learner, $course);
        $this->createTemplate(['course_ids' => [$course->id], 'min_progress_percent' => 10]);

        $payload = ['telefonnummer' => '+49 170 1234567', 'geschlecht' => 'Männlich', 'tauglichkeit' => '1'];
        $first = $this->actingAsInTenant($learner, $tenant)->post(route('praxis-pruefung.store', $course), $payload);
        $second = $this->actingAsInTenant($learner, $tenant)->post(route('praxis-pruefung.store', $course), $payload);

        $first->assertRedirect(route('praxis-pruefung.index', $course));
        $this->assertFalse($second->isServerError());

        $this->onAdmin(function () use ($tenant, $learner, $course) {
            $this->assertSame(1, PraxisFormSubmission::where('tenant_id', $tenant->id)
                ->where('user_id', $learner->id)->where('course_id', $course->id)->count());
        });
    }

    public function test_submission_requires_required_fields(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        $this->masterAllQuestions($tenant, $learner, $course);
        $this->createTemplate(['course_ids' => [$course->id], 'min_progress_percent' => 10]);

        $response = $this->actingAsInTenant($learner, $tenant)->post(route('praxis-pruefung.store', $course), []);

        $response->assertSessionHasErrors(['telefonnummer', 'geschlecht']);
        $this->onAdmin(fn () => $this->assertDatabaseMissing('praxis_form_submission', ['tenant_id' => $tenant->id, 'user_id' => $learner->id]));
    }

    public function test_submission_is_rejected_below_the_threshold(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        $this->createTemplate(['course_ids' => [$course->id], 'min_progress_percent' => 50]);

        $response = $this->actingAsInTenant($learner, $tenant)->post(route('praxis-pruefung.store', $course), [
            'telefonnummer' => '+49 170 1234567',
            'geschlecht' => 'Männlich',
        ]);

        $response->assertForbidden();
    }

    public function test_form_shows_a_location_select_populated_from_the_schools_locations(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        $this->masterAllQuestions($tenant, $learner, $course);
        $this->createTemplate([
            'course_ids' => [$course->id],
            'min_progress_percent' => 10,
            'fields' => [
                ['key' => 'ort', 'label' => 'Ort', 'type' => 'location', 'required' => true, 'options' => [], 'help_text' => null],
            ],
        ]);
        $this->onAdmin(fn () => TenantCourseLocation::create([
            'tenant_id' => $tenant->id,
            'praxis_ort' => 'Standort Nord',
            'pruefung_ort' => 'Prüfstelle Süd',
            'course_ids' => [$course->id],
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course));

        $response->assertOk();
        $response->assertSee('Standort Nord');
        $response->assertSee('Prüfstelle Süd');
        // Ort ohne Koordinaten -- die Karte darf nicht gerendert werden.
        $response->assertDontSee('pruefungsort-map', false);
    }

    public function test_location_field_is_skipped_when_no_locations_are_configured(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        $this->masterAllQuestions($tenant, $learner, $course);
        $this->createTemplate([
            'course_ids' => [$course->id],
            'min_progress_percent' => 10,
            'fields' => [
                ['key' => 'ort', 'label' => 'Ort', 'type' => 'location', 'required' => true, 'options' => [], 'help_text' => null],
            ],
        ]);

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course));
        $response->assertOk();

        $submitResponse = $this->actingAsInTenant($learner, $tenant)->post(route('praxis-pruefung.store', $course), []);
        $submitResponse->assertSessionDoesntHaveErrors('ort');
        $submitResponse->assertRedirect(route('praxis-pruefung.index', $course));
    }

    public function test_map_with_exam_locations_is_shown_when_coordinates_are_configured(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        $this->masterAllQuestions($tenant, $learner, $course);
        $this->createTemplate([
            'course_ids' => [$course->id],
            'min_progress_percent' => 10,
            'fields' => [
                ['key' => 'ort', 'label' => 'Ort', 'type' => 'location', 'required' => true, 'options' => [], 'help_text' => null],
            ],
        ]);
        $this->onAdmin(fn () => TenantCourseLocation::create([
            'tenant_id' => $tenant->id,
            'praxis_ort' => 'Standort Nord',
            'pruefung_ort' => 'Prüfstelle Süd',
            'pruefung_lat' => 53.5460740,
            'pruefung_lng' => 9.9826940,
            'course_ids' => [$course->id],
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course));

        $response->assertOk();
        $response->assertSee('pruefungsort-map', false);
        $response->assertSee('leaflet', false);
        $response->assertSee('53.546074', false);
        $response->assertSee('Prüfstelle Süd');
    }

    /**
     * Sicherheitsreview S-N1: die Marker-Popups der Ortskarte bauten ihr
     * HTML bislang per String-Konkatenation ('<strong>' + ort.name + ...)
     * und übergaben es Leaflets bindPopup() als rohen HTML-String -- ein
     * per <img onerror=...> oder <svg onload=...> präparierter Orts-/
     * Adressname wäre damit ein gespeichertes XSS gegen jeden Betrachter
     * der Seite gewesen. Da die eigentliche Schwachstelle erst zur
     * Laufzeit im Browser-JS entsteht (nicht im Server-Response selbst --
     *
     * @json() escaped bereits HTML-kritische Zeichen im JSON-Blob), prüft
     * dieser Test serverseitig, dass das verwundbare String-Muster aus
     * der Seite entfernt ist und durch den sicheren DOM-/textContent-
     * Aufbau ersetzt wurde.
     */
    public function test_the_map_popup_is_built_via_safe_dom_text_content_not_raw_html_concatenation(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        $this->masterAllQuestions($tenant, $learner, $course);
        $this->createTemplate([
            'course_ids' => [$course->id],
            'min_progress_percent' => 10,
            'fields' => [
                ['key' => 'ort', 'label' => 'Ort', 'type' => 'location', 'required' => true, 'options' => [], 'help_text' => null],
            ],
        ]);
        $this->onAdmin(fn () => TenantCourseLocation::create([
            'tenant_id' => $tenant->id,
            'praxis_ort' => 'Standort Nord',
            'pruefung_ort' => '<img src=x onerror=alert(1)>',
            'pruefung_lat' => 53.5460740,
            'pruefung_lng' => 9.9826940,
            'course_ids' => [$course->id],
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString(
            "'<strong>' + ort.name",
            $html,
            'Das Popup wird noch per unsicherer String-Konkatenation gebaut.'
        );
        $this->assertStringContainsString('marker.bindPopup(popup)', $html);
        $this->assertStringContainsString('name.textContent = ort.name', $html);

        // @json() escaped < und > zu Unicode-Escapes (JSON_HEX_TAG) -- der
        // Payload selbst darf deshalb nirgends als rohes HTML-Tag im
        // Response auftauchen.
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
    }

    public function test_submitting_a_location_stores_the_coupled_exam_location(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        $this->masterAllQuestions($tenant, $learner, $course);
        $template = $this->createTemplate([
            'course_ids' => [$course->id],
            'min_progress_percent' => 10,
            'fields' => [
                ['key' => 'ort', 'label' => 'Ort', 'type' => 'location', 'required' => true, 'options' => [], 'help_text' => null],
            ],
        ]);
        $location = $this->onAdmin(fn () => TenantCourseLocation::create([
            'tenant_id' => $tenant->id,
            'praxis_ort' => 'Standort Nord',
            'pruefung_ort' => 'Prüfstelle Süd',
            'course_ids' => [$course->id],
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->post(route('praxis-pruefung.store', $course), [
            'ort' => $location->id,
        ]);

        $response->assertRedirect(route('praxis-pruefung.index', $course));

        $this->onAdmin(function () use ($tenant, $learner, $course) {
            $submission = PraxisFormSubmission::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->where('course_id', $course->id)->first();
            $this->assertSame('Standort Nord', $submission->data['ort']);
            $this->assertSame('Prüfstelle Süd', $submission->data['ort_pruefungsort']);
        });

        $follow = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course));
        $follow->assertOk();
        $follow->assertSee('Standort Nord');
        $follow->assertSee('Prüfstelle Süd');
    }

    public function test_submission_stores_and_shows_the_full_address_of_both_locations(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        $this->masterAllQuestions($tenant, $learner, $course);
        $template = $this->createTemplate([
            'course_ids' => [$course->id],
            'min_progress_percent' => 10,
            'fields' => [
                ['key' => 'ort', 'label' => 'Ort', 'type' => 'location', 'required' => true, 'options' => [], 'help_text' => null],
            ],
        ]);
        $location = $this->onAdmin(fn () => TenantCourseLocation::create([
            'tenant_id' => $tenant->id,
            'praxis_ort' => 'Standort Nord',
            'praxis_strasse' => 'Nordweg 1',
            'praxis_plz' => '11111',
            'praxis_stadt' => 'Nordstadt',
            'praxis_land' => 'Deutschland',
            'pruefung_ort' => 'Prüfstelle Süd',
            'pruefung_strasse' => 'Südallee 2',
            'pruefung_plz' => '22222',
            'pruefung_stadt' => 'Südstadt',
            'pruefung_land' => 'Österreich',
            'course_ids' => [$course->id],
        ]));

        $this->actingAsInTenant($learner, $tenant)->post(route('praxis-pruefung.store', $course), [
            'ort' => $location->id,
        ])->assertRedirect(route('praxis-pruefung.index', $course));

        $this->onAdmin(function () use ($tenant, $learner, $course) {
            $submission = PraxisFormSubmission::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->where('course_id', $course->id)->first();
            $this->assertSame('Nordweg 1, 11111 Nordstadt, Deutschland', $submission->data['ort_adresse']);
            $this->assertSame('Südallee 2, 22222 Südstadt, Österreich', $submission->data['ort_pruefungsort_adresse']);
        });

        $follow = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course));
        $follow->assertOk();
        $follow->assertSee('Nordweg 1, 11111 Nordstadt, Deutschland');
        $follow->assertSee('Südallee 2, 22222 Südstadt, Österreich');
    }

    public function test_submission_stores_and_shows_the_optional_theorieort_when_present(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        $this->masterAllQuestions($tenant, $learner, $course);
        $template = $this->createTemplate([
            'course_ids' => [$course->id],
            'min_progress_percent' => 10,
            'fields' => [
                ['key' => 'ort', 'label' => 'Ort', 'type' => 'location', 'required' => true, 'options' => [], 'help_text' => null],
            ],
        ]);
        $location = $this->onAdmin(fn () => TenantCourseLocation::create([
            'tenant_id' => $tenant->id,
            'praxis_ort' => 'Standort Nord',
            'pruefung_ort' => 'Prüfstelle Süd',
            'theorie_ort' => 'Theoriezentrum Mitte',
            'theorie_strasse' => 'Mittelweg 5',
            'theorie_plz' => '33333',
            'theorie_stadt' => 'Mittelstadt',
            'theorie_land' => 'Deutschland',
            'course_ids' => [$course->id],
        ]));

        $this->actingAsInTenant($learner, $tenant)->post(route('praxis-pruefung.store', $course), [
            'ort' => $location->id,
        ])->assertRedirect(route('praxis-pruefung.index', $course));

        $this->onAdmin(function () use ($tenant, $learner, $course) {
            $submission = PraxisFormSubmission::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->where('course_id', $course->id)->first();
            $this->assertSame('Theoriezentrum Mitte', $submission->data['ort_theorieort']);
            $this->assertSame('Mittelweg 5, 33333 Mittelstadt, Deutschland', $submission->data['ort_theorieort_adresse']);
        });

        $follow = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course));
        $follow->assertOk();
        $follow->assertSee('Theoriezentrum Mitte');
        $follow->assertSee('Mittelweg 5, 33333 Mittelstadt, Deutschland');
    }

    public function test_submission_omits_theorieort_fields_when_not_configured(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        $this->masterAllQuestions($tenant, $learner, $course);
        $this->createTemplate([
            'course_ids' => [$course->id],
            'min_progress_percent' => 10,
            'fields' => [
                ['key' => 'ort', 'label' => 'Ort', 'type' => 'location', 'required' => true, 'options' => [], 'help_text' => null],
            ],
        ]);
        $location = $this->onAdmin(fn () => TenantCourseLocation::create([
            'tenant_id' => $tenant->id,
            'praxis_ort' => 'Standort Nord',
            'pruefung_ort' => 'Prüfstelle Süd',
            'course_ids' => [$course->id],
        ]));

        $this->actingAsInTenant($learner, $tenant)->post(route('praxis-pruefung.store', $course), [
            'ort' => $location->id,
        ])->assertRedirect(route('praxis-pruefung.index', $course));

        $this->onAdmin(function () use ($tenant, $learner, $course) {
            $submission = PraxisFormSubmission::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->where('course_id', $course->id)->first();
            $this->assertArrayNotHasKey('ort_theorieort', $submission->data);
            $this->assertArrayNotHasKey('ort_theorieort_adresse', $submission->data);
        });
    }

    public function test_submission_stores_and_shows_the_assigned_pruefungsausschuss_when_present(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        $this->masterAllQuestions($tenant, $learner, $course);
        $this->createTemplate([
            'course_ids' => [$course->id],
            'min_progress_percent' => 10,
            'fields' => [
                ['key' => 'ort', 'label' => 'Ort', 'type' => 'location', 'required' => true, 'options' => [], 'help_text' => null],
            ],
        ]);
        $pruefungsausschuss = $this->onAdmin(fn () => Pruefungsausschuss::create([
            'kommission' => 'Prüfungsausschuss Testregion',
            'standorttyp' => 'Geschäftsstelle',
            'strasse' => 'Ausschussweg 9',
            'plz' => '44444',
            'stadt' => 'Ausschussstadt',
            'course_ids' => [$course->id],
        ]));
        $this->createdPruefungsausschussIds[] = $pruefungsausschuss->id;
        $location = $this->onAdmin(fn () => TenantCourseLocation::create([
            'tenant_id' => $tenant->id,
            'praxis_ort' => 'Standort Nord',
            'pruefung_ort' => 'Prüfstelle Süd',
            'pruefungsausschuss_id' => $pruefungsausschuss->id,
            'course_ids' => [$course->id],
        ]));

        $this->actingAsInTenant($learner, $tenant)->post(route('praxis-pruefung.store', $course), [
            'ort' => $location->id,
        ])->assertRedirect(route('praxis-pruefung.index', $course));

        $this->onAdmin(function () use ($tenant, $learner, $course) {
            $submission = PraxisFormSubmission::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->where('course_id', $course->id)->first();
            $this->assertSame('Prüfungsausschuss Testregion — Geschäftsstelle', $submission->data['ort_pruefungsausschuss']);
            $this->assertSame('Ausschussweg 9, 44444 Ausschussstadt', $submission->data['ort_pruefungsausschuss_adresse']);
            $this->assertSame(['Test-Kurs für Praxis & Prüfung'], $submission->data['ort_pruefungsausschuss_kurse']);
        });

        $follow = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course));
        $follow->assertOk();
        $follow->assertSee('Prüfungsausschuss Testregion — Geschäftsstelle');
        $follow->assertSee('Ausschussweg 9, 44444 Ausschussstadt');
        $follow->assertSee('Zuständig für:');
        $follow->assertSee('Test-Kurs für Praxis & Prüfung');
    }

    public function test_submission_omits_pruefungsausschuss_fields_when_not_assigned(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        $this->masterAllQuestions($tenant, $learner, $course);
        $this->createTemplate([
            'course_ids' => [$course->id],
            'min_progress_percent' => 10,
            'fields' => [
                ['key' => 'ort', 'label' => 'Ort', 'type' => 'location', 'required' => true, 'options' => [], 'help_text' => null],
            ],
        ]);
        $location = $this->onAdmin(fn () => TenantCourseLocation::create([
            'tenant_id' => $tenant->id,
            'praxis_ort' => 'Standort Nord',
            'pruefung_ort' => 'Prüfstelle Süd',
            'course_ids' => [$course->id],
        ]));

        $this->actingAsInTenant($learner, $tenant)->post(route('praxis-pruefung.store', $course), [
            'ort' => $location->id,
        ])->assertRedirect(route('praxis-pruefung.index', $course));

        $this->onAdmin(function () use ($tenant, $learner, $course) {
            $submission = PraxisFormSubmission::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->where('course_id', $course->id)->first();
            $this->assertArrayNotHasKey('ort_pruefungsausschuss', $submission->data);
            $this->assertArrayNotHasKey('ort_pruefungsausschuss_adresse', $submission->data);
        });
    }

    public function test_submitting_an_invalid_location_id_is_rejected(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        $this->masterAllQuestions($tenant, $learner, $course);
        $this->createTemplate([
            'course_ids' => [$course->id],
            'min_progress_percent' => 10,
            'fields' => [
                ['key' => 'ort', 'label' => 'Ort', 'type' => 'location', 'required' => true, 'options' => [], 'help_text' => null],
            ],
        ]);
        $this->onAdmin(fn () => TenantCourseLocation::create([
            'tenant_id' => $tenant->id,
            'praxis_ort' => 'Standort Nord',
            'pruefung_ort' => 'Standort Nord',
            'course_ids' => [$course->id],
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->post(route('praxis-pruefung.store', $course), [
            'ort' => (string) Str::uuid(),
        ]);

        $response->assertSessionHasErrors('ort');
    }

    public function test_tauglichkeit_hint_is_shown_when_enabled_on_the_template(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        $this->masterAllQuestions($tenant, $learner, $course);
        $this->createTemplate(['course_ids' => [$course->id], 'min_progress_percent' => 10, 'requires_tauglichkeit' => true]);

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course));

        $response->assertOk();
        $response->assertSee('ärztliche Tauglichkeitsbescheinigung');
        $response->assertSee(route('tips.index'), false);

        // Der Hinweis muss unter der Tauglichkeits-Checkbox stehen, nicht
        // ganz oben im Formular vor den übrigen Feldern.
        $html = $response->getContent();
        $this->assertGreaterThan(
            strpos($html, 'Ich habe bereits die Tauglichkeit'),
            strpos($html, 'ärztliche Tauglichkeitsbescheinigung'),
        );
    }

    public function test_tauglichkeit_hint_falls_back_to_the_end_of_the_form_without_a_matching_field(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        $this->masterAllQuestions($tenant, $learner, $course);
        $this->createTemplate([
            'course_ids' => [$course->id],
            'min_progress_percent' => 10,
            'requires_tauglichkeit' => true,
            'fields' => [
                ['key' => 'telefonnummer', 'label' => 'Telefonnummer', 'type' => 'text', 'required' => true, 'options' => [], 'help_text' => null],
            ],
        ]);

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course));

        $response->assertOk();
        $response->assertSee('ärztliche Tauglichkeitsbescheinigung');

        $html = $response->getContent();
        $this->assertGreaterThan(
            strpos($html, 'Telefonnummer'),
            strpos($html, 'ärztliche Tauglichkeitsbescheinigung'),
        );
    }

    public function test_tauglichkeit_hint_is_hidden_when_disabled_on_the_template(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        $this->masterAllQuestions($tenant, $learner, $course);
        $this->createTemplate(['course_ids' => [$course->id], 'min_progress_percent' => 10, 'requires_tauglichkeit' => false]);

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course));

        $response->assertOk();
        $response->assertDontSee('ärztliche Tauglichkeitsbescheinigung');
    }

    public function test_recommended_addon_hint_is_shown_when_an_active_addon_targets_this_course(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        $this->masterAllQuestions($tenant, $learner, $course);
        $this->createTemplate(['course_ids' => [$course->id], 'min_progress_percent' => 10]);

        $addonTemplate = $this->onAdmin(fn () => AddonTemplate::create([
            'title' => 'E2E Großes Paket',
            'headline' => 'Sichere dir jetzt das große Paket',
            'cta_label' => 'Paket sichern',
            'bullets' => ['Alle Praxisstunden inklusive'],
            'active' => true,
        ]));
        $this->createdAddonTemplateIds[] = $addonTemplate->id;
        $this->onAdmin(fn () => TenantAddon::create([
            'tenant_id' => $tenant->id,
            'addon_template_id' => $addonTemplate->id,
            'active' => true,
            'price_label' => '299 €',
            'trigger_course_id' => $course->id,
            'trigger_percent' => 0,
            'offer_url' => 'https://example.test/angebot',
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course));

        $response->assertOk();
        $response->assertSee('Sichere dir jetzt das große Paket');
        $response->assertSee('https://example.test/angebot', false);
    }

    public function test_recommended_addon_hint_is_hidden_below_its_trigger_percent(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        // Kein masterAllQuestions() -- Fortschritt bleibt bei 0%, unter der
        // Addon-Schwelle, aber die Formular-Hürde selbst ist 0%, damit der
        // 'form'-Zustand trotzdem erreicht wird.
        $this->createTemplate(['course_ids' => [$course->id], 'min_progress_percent' => 0]);

        $addonTemplate = $this->onAdmin(fn () => AddonTemplate::create([
            'title' => 'E2E Großes Paket (hohe Schwelle)',
            'headline' => 'Sichere dir jetzt das große Paket (hohe Schwelle)',
            'cta_label' => 'Paket sichern',
            'active' => true,
        ]));
        $this->createdAddonTemplateIds[] = $addonTemplate->id;
        $this->onAdmin(fn () => TenantAddon::create([
            'tenant_id' => $tenant->id,
            'addon_template_id' => $addonTemplate->id,
            'active' => true,
            'trigger_course_id' => $course->id,
            'trigger_percent' => 50,
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course));

        $response->assertOk();
        $response->assertDontSee('Sichere dir jetzt das große Paket (hohe Schwelle)');
    }

    public function test_recommended_addon_hint_is_hidden_without_a_matching_addon(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        $this->masterAllQuestions($tenant, $learner, $course);
        $this->createTemplate(['course_ids' => [$course->id], 'min_progress_percent' => 10]);

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course));

        $response->assertOk();
        $response->assertDontSee('rounded-xl p-4 mb-5 bg-amber-50', false);
    }
}
