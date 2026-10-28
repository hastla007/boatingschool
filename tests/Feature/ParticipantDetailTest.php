<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\PraxisExamAppointment;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Models\Product;
use App\Models\ProductPurchase;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\WillkommensboxOrder;
use App\Notifications\WillkommensboxOrderShipped;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Detailseite je Teilnehmer (ParticipantController::show): Adresse,
 * gebuchte Kurse/Produkte und Praxis-Anmeldungen an einem Ort, erreichbar
 * über den Namen in der Teilnehmer-Tabelle (admin/tabs/participants.blade.php).
 */
class ParticipantDetailTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdTemplateIds = [];

    private array $createdCodes = [];

    private array $createdCourseIds = [];

    protected function tearDown(): void
    {
        // Erst Tenants (kaskadiert praxis_form_submission weg), dann die
        // Vorlage selbst -- sonst verhindert der FK auf praxis_form_template
        // das Löschen, solange noch eine Submission darauf verweist.
        $this->cleanUpCreatedTenantsAndUsers();

        $this->onAdmin(function () {
            foreach ($this->createdTemplateIds as $id) {
                PraxisFormTemplate::where('id', $id)->delete();
            }
            foreach ($this->createdCodes as $code) {
                Coupon::where('code', $code)->delete();
            }
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }
        });

        parent::tearDown();
    }

    public function test_admin_sees_a_participants_address_and_bookings_on_the_detail_page(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner', [
            'street' => 'Hafenstraße 5',
            'postal_code' => '20457',
            'city' => 'Hamburg',
            'country' => 'Deutschland',
            'birthdate' => '1995-06-15',
        ]);
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);
        $product = $this->onAdmin(fn () => Product::where('active', true)->firstOrFail());
        $this->onAdmin(fn () => ProductPurchase::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'product_id' => $product->id,
            'source_type' => 'manual',
            'source_reference' => 'admin:'.$admin->id,
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");

        $response->assertOk();
        $response->assertSee('value="Hafenstraße 5"', false);
        $response->assertSee('value="20457"', false);
        $response->assertSee('value="Hamburg"', false);
        $response->assertSee('value="1995-06-15"', false);
        $response->assertSee('value="Deutschland" selected', false);
        $response->assertSee($course->name);
        $response->assertSee($product->name);
        // Mit vorhandenen Daten startet die Karte im Lesemodus (Bearbeiten-Button), nicht im Formular.
        $response->assertSee('x-data="{ editing: false }"', false);
        $response->assertSee('15.06.1995');
        $response->assertSee('Bearbeiten');
    }

    public function test_address_section_starts_in_edit_mode_without_any_saved_data(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");

        $response->assertOk();
        $response->assertSee('x-data="{ editing: true }"', false);
    }

    public function test_address_section_starts_in_edit_mode_after_a_validation_error(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner', [
            'street' => 'Hafenstraße 5', 'postal_code' => '20457', 'city' => 'Hamburg',
            'country' => 'Deutschland', 'birthdate' => '1995-06-15',
        ]);

        $actor = $this->actingAsInTenant($admin, $tenant);
        $actor->get("/admin/participants/{$learner->id}");

        $response = $actor->followingRedirects()->patch("/admin/participants/{$learner->id}", [
            'country' => 'Elbonien',
        ]);

        $response->assertOk();
        $response->assertSee('x-data="{ editing: true }"', false);
    }

    public function test_detail_page_is_linked_from_the_participants_table(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=participants');

        $response->assertOk();
        $response->assertSee(route('admin.participants.show', $learner->id), false);
    }

    public function test_admin_sees_the_submitted_praxis_form_data_on_the_detail_page(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');

        $template = $this->onAdmin(function () {
            $template = PraxisFormTemplate::create([
                'title' => 'E2E Anmeldeformular',
                'min_progress_percent' => 0,
                'course_ids' => [],
                'fields' => [
                    ['key' => 'telefonnummer', 'label' => 'Telefonnummer', 'type' => 'text', 'required' => true, 'options' => [], 'help_text' => null],
                    ['key' => 'tauglich', 'label' => 'Tauglichkeitsbescheinigung liegt vor', 'type' => 'checkbox', 'required' => false, 'options' => [], 'help_text' => null],
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
            'data' => ['telefonnummer' => '+49 170 7654321', 'tauglich' => true],
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");

        $response->assertOk();
        $response->assertSee('E2E Anmeldeformular');
        $response->assertSee('Telefonnummer');
        $response->assertSee('+49 170 7654321');
        $response->assertSee('Tauglichkeitsbescheinigung liegt vor');
        $response->assertSee('Ja');
    }

    public function test_detail_page_shows_a_placeholder_when_no_praxis_form_was_submitted_yet(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");

        $response->assertOk();
        $response->assertSee('Noch keine Praxis-Anmeldung eingegangen.');
        $response->assertSee('Keine offenen Aktionen.');
    }

    /**
     * Eigener isolierter Kurs statt eines echten Katalogkurses, dessen
     * Demo-Praxisformular (siehe PraxisFormTemplateSeeder) sonst mit einer
     * eigens angelegten Vorlage kollidieren würde.
     */
    private function createPraxisSubmission(Tenant $tenant, User $learner): PraxisFormSubmission
    {
        $course = $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-NOTIFY-'.uniqid(),
                'name' => 'Test-Kurs für Benachrichtigungen',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            return $course;
        });

        $this->grantEntitlement($tenant, $learner, $course);

        $template = $this->onAdmin(function () use ($course) {
            $template = PraxisFormTemplate::create([
                'title' => 'E2E Benachrichtigungs-Formular',
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

    public function test_detail_page_notifies_about_a_new_submission_without_an_exam_date(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createPraxisSubmission($tenant, $learner);

        $response = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");

        $response->assertOk();
        $response->assertSee('Benachrichtigungen');
        $response->assertSee($submission->course->name);
        $response->assertSee('Neue Anmeldung -- bitte Prüfungstermin vorschlagen.');
        $response->assertDontSee('Keine offenen Aktionen.');
    }

    public function test_detail_page_notifies_about_a_declined_exam_date_proposal(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createPraxisSubmission($tenant, $learner);

        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2027-06-01 09:00', 'slot' => 0]
        );
        $appointment = $this->onAdmin(fn () => PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->first());
        $this->actingAsInTenant($learner, $tenant)->post(
            route('praxis-pruefung.exam-appointments.respond', [$submission->course_id, $appointment->id]),
            ['decision' => 'declined', 'comment' => 'Passt leider nicht.']
        );

        $response = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");

        $response->assertOk();
        $response->assertSee('Vorschlag abgelehnt -- bitte neuen Termin vorschlagen.');
        $response->assertDontSee('Keine offenen Aktionen.');
    }

    public function test_detail_page_has_no_open_action_while_a_proposal_is_pending(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createPraxisSubmission($tenant, $learner);

        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2027-06-01 09:00', 'slot' => 0]
        );

        $response = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");

        $response->assertOk();
        // Wartet auf Rückmeldung des Teilnehmers -- die Bootsschule ist
        // hier noch nicht am Zug.
        $response->assertSee('Keine offenen Aktionen.');
    }

    public function test_detail_page_has_no_open_action_once_the_exam_date_is_accepted(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->createPraxisSubmission($tenant, $learner);

        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2027-06-01 09:00', 'slot' => 0]
        );
        $appointment = $this->onAdmin(fn () => PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->first());
        $this->actingAsInTenant($learner, $tenant)->post(
            route('praxis-pruefung.exam-appointments.respond', [$submission->course_id, $appointment->id]),
            ['decision' => 'accepted']
        );

        $response = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");

        $response->assertOk();
        $response->assertSee('Keine offenen Aktionen.');
    }

    public function test_bundled_options_from_a_course_coupon_are_shown_under_that_course(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $willkommensbox = $this->onAdmin(fn () => Product::where('code', 'LERNPLATTFORM-WB')->firstOrFail());
        $kleinePraxis = $this->onAdmin(fn () => Product::where('code', 'PRAXISEINHEIT-KLEIN')->firstOrFail());
        $code = 'E2E-DETAIL-OPTIONS';
        $this->createdCodes[] = $code;
        $this->onAdmin(function () use ($code, $course, $willkommensbox, $kleinePraxis, $tenant) {
            $coupon = Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]);
            $coupon->optionProducts()->attach([$willkommensbox->id, $kleinePraxis->id]);
        });
        $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $code]);

        $response = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");

        $response->assertOk();
        // Der Kurs erscheint als eigene Buchung, die Optionen als
        // Zusatzzeile darunter statt als eigenständige Buchungen.
        $response->assertSeeInOrder([$course->name, $willkommensbox->name, $kleinePraxis->name]);
        $response->assertSeeText('+ '.$willkommensbox->name.', '.$kleinePraxis->name);
    }

    public function test_a_standalone_product_purchase_without_a_matching_course_is_shown_on_its_own(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $product = $this->onAdmin(fn () => Product::where('active', true)->firstOrFail());
        $this->onAdmin(fn () => ProductPurchase::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'product_id' => $product->id,
            'source_type' => 'manual',
            'source_reference' => 'admin:'.$admin->id,
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");

        $response->assertOk();
        $response->assertSee($product->name);
        $response->assertDontSee('+ '.$product->name);
    }

    public function test_admin_cannot_view_a_participant_from_another_tenant(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $adminA = $this->createTenantUser($tenantA, 'owner');
        $learnerB = $this->createTenantUser($tenantB, 'learner');

        $response = $this->actingAsInTenant($adminA, $tenantA)->get("/admin/participants/{$learnerB->id}");

        $response->assertNotFound();
    }

    public function test_admin_can_add_an_address_and_birthdate_to_a_participant_without_one(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($admin, $tenant)->patch("/admin/participants/{$learner->id}", [
            'street' => 'Hafenstraße 5',
            'postal_code' => '20457',
            'city' => 'Hamburg',
            'country' => 'Deutschland',
            'birthdate' => '1995-06-15',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->onAdmin(function () use ($learner, $tenant) {
            $membership = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->first();
            $this->assertSame('Hafenstraße 5', $membership->street);
            $this->assertSame('20457', $membership->postal_code);
            $this->assertSame('Hamburg', $membership->city);
            $this->assertSame('Deutschland', $membership->country);
            $this->assertSame('1995-06-15', $membership->birthdate->format('Y-m-d'));
            $this->assertTrue($learner->fresh()->hasCompleteRegistrationProfile($tenant));
        });
    }

    public function test_admin_can_change_an_existing_address_and_birthdate(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner', [
            'street' => 'Alte Straße 1',
            'postal_code' => '10000',
            'city' => 'Altstadt',
            'country' => 'Österreich',
            'birthdate' => '1990-01-01',
        ]);

        $response = $this->actingAsInTenant($admin, $tenant)->patch("/admin/participants/{$learner->id}", [
            'street' => 'Neue Straße 2',
            'postal_code' => '20000',
            'city' => 'Neustadt',
            'country' => 'Deutschland',
            'birthdate' => '1991-02-02',
        ]);

        $response->assertRedirect();
        $this->onAdmin(function () use ($learner, $tenant) {
            $membership = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->first();
            $this->assertSame('Neue Straße 2', $membership->street);
            $this->assertSame('20000', $membership->postal_code);
            $this->assertSame('Neustadt', $membership->city);
            $this->assertSame('Deutschland', $membership->country);
            $this->assertSame('1991-02-02', $membership->birthdate->format('Y-m-d'));
        });
    }

    /**
     * Sicherheitsreview S3: Adresse und Geburtsdatum standen bisher direkt
     * auf app_user -- EINE einzige, kontoweite Kopie, unabhängig davon, bei
     * wie vielen Bootsschulen das Konto Mitglied ist. Ist ein Lerner
     * gleichzeitig Mitglied bei Schule A und Schule B, sah und änderte
     * Schule A damit dieselbe Adresse/dasselbe Geburtsdatum, die Schule B
     * für ihre eigene Prüfungsanmeldung verwendet.
     */
    public function test_a_participants_address_is_isolated_between_two_tenant_memberships(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $adminA = $this->createTenantUser($tenantA, 'owner');
        $learner = $this->createTenantUser($tenantA, 'learner');

        $this->onAdmin(fn () => TenantUser::create([
            'tenant_id' => $tenantB->id,
            'user_id' => $learner->id,
            'role' => 'learner',
            'status' => 'active',
            'street' => 'Schule-B-Straße 1',
            'postal_code' => '50000',
            'city' => 'Schule-B-Stadt',
            'country' => 'Deutschland',
            'birthdate' => '1988-03-03',
        ]));

        $this->actingAsInTenant($adminA, $tenantA)->patch("/admin/participants/{$learner->id}", [
            'street' => 'Schule-A-Straße 9',
            'postal_code' => '10000',
            'city' => 'Schule-A-Stadt',
            'country' => 'Österreich',
            'birthdate' => '1999-09-09',
        ])->assertRedirect();

        $this->onAdmin(function () use ($learner, $tenantA, $tenantB) {
            $membershipA = TenantUser::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->where('user_id', $learner->id)->first();
            $membershipB = TenantUser::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->where('user_id', $learner->id)->first();

            $this->assertSame('Schule-A-Straße 9', $membershipA->street);

            // Schule B muss von der Änderung durch Schule A unberührt bleiben.
            $this->assertSame('Schule-B-Straße 1', $membershipB->street);
            $this->assertSame('50000', $membershipB->postal_code);
            $this->assertSame('Schule-B-Stadt', $membershipB->city);
            $this->assertSame('Deutschland', $membershipB->country);
            $this->assertSame('1988-03-03', $membershipB->birthdate->format('Y-m-d'));
        });
    }

    public function test_updating_a_participant_rejects_an_unsupported_country(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($admin, $tenant)->patch("/admin/participants/{$learner->id}", [
            'country' => 'Elbonien',
        ]);

        $response->assertSessionHasErrors('country');
    }

    public function test_updating_a_participant_rejects_a_future_birthdate(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($admin, $tenant)->patch("/admin/participants/{$learner->id}", [
            'birthdate' => now()->addDay()->format('Y-m-d'),
        ]);

        $response->assertSessionHasErrors('birthdate');
    }

    public function test_admin_cannot_update_a_participant_from_another_tenant(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $adminA = $this->createTenantUser($tenantA, 'owner');
        $learnerB = $this->createTenantUser($tenantB, 'learner');

        $response = $this->actingAsInTenant($adminA, $tenantA)->patch("/admin/participants/{$learnerB->id}", [
            'city' => 'Sollte nicht ankommen',
        ]);

        $response->assertNotFound();
        $this->onAdmin(function () use ($learnerB, $tenantB) {
            // withoutGlobalScopes(): die vorangehende Anfrage hinterlässt den
            // TenantContext auf $tenantA (BelongsToTenant::bootBelongsToTenant()
            // filtert sonst zusätzlich implizit danach) -- hier wird aber
            // bewusst die Mitgliedschaft bei $tenantB geprüft.
            $membership = TenantUser::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->where('user_id', $learnerB->id)->first();
            $this->assertNull($membership->city);
        });
    }

    private function grantWillkommensbox($tenant, $user): void
    {
        $product = $this->onAdmin(fn () => Product::where('code', 'LERNPLATTFORM-WB')->firstOrFail());
        $this->onAdmin(fn () => ProductPurchase::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'source_type' => 'coupon',
            'source_reference' => 'E2E-WB-CODE',
        ]));
    }

    public function test_detail_page_shows_the_willkommensbox_order_address(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantWillkommensbox($tenant, $learner);
        $this->onAdmin(fn () => WillkommensboxOrder::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'street' => 'Lieferstraße 1',
            'postal_code' => '12345',
            'city' => 'Lieferstadt',
            'country' => 'Deutschland',
            'note' => 'Bitte beim Nachbarn abgeben.',
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");

        $response->assertOk();
        $response->assertSee('Willkommensbox');
        $response->assertSee('Lieferstraße 1');
        $response->assertSee('12345');
        $response->assertSee('Lieferstadt');
        $response->assertSee('Bitte beim Nachbarn abgeben.');
        $response->assertSee('Als versendet markieren');
        $response->assertSee('Bestellung eingegangen');
        // Taucht auch in der Benachrichtigungsbox neben den
        // Praxis-Anmeldungen auf, nicht nur in der Willkommensbox-Karte.
        $response->assertSee('bitte Willkommensbox versenden.');
        $response->assertDontSee('Keine offenen Aktionen.');
    }

    /**
     * Bug-Review (Willkommensbox -- Tab läuft auf dem Handy über): die
     * Adresse- und die Willkommensbox-Karte liegen nebeneinander in einem
     * grid-Container, der unterhalb von lg (z. B. 360px) bisher keine
     * explizite Spaltenzahl hatte -- ohne grid-cols-1 bleibt die Breite
     * content-based (shrink-to-fit) statt zu stapeln, wodurch beide Karten
     * über den Viewport hinausliefen (derselbe Fehler wie Bug-Review P8).
     */
    public function test_the_detail_page_layout_grid_has_an_explicit_single_column_below_the_desktop_breakpoint(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");

        $response->assertOk();
        $response->assertSee('grid grid-cols-1 lg:grid-cols-2', false);
    }

    public function test_detail_page_hides_the_shipping_notice_once_the_order_is_shipped(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantWillkommensbox($tenant, $learner);
        $this->onAdmin(fn () => WillkommensboxOrder::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'street' => 'Lieferstraße 1',
            'postal_code' => '12345',
            'city' => 'Lieferstadt',
            'country' => 'Deutschland',
            'shipped_at' => now(),
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");

        $response->assertOk();
        $response->assertDontSee('Bestellung eingegangen');
        $response->assertSee('Keine offenen Aktionen.');
    }

    public function test_detail_page_shows_a_hint_when_access_exists_but_nothing_was_ordered_yet(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantWillkommensbox($tenant, $learner);

        $response = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");

        $response->assertOk();
        $response->assertSee('Zugang vorhanden, aber noch nicht bestellt.');
    }

    public function test_detail_page_does_not_show_willkommensbox_section_without_access(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");

        $response->assertOk();
        $response->assertDontSee('Willkommensbox');
    }

    public function test_admin_can_mark_a_willkommensbox_order_as_shipped_with_a_tracking_number(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantWillkommensbox($tenant, $learner);
        $order = $this->onAdmin(fn () => WillkommensboxOrder::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'street' => 'Lieferstraße 1',
            'postal_code' => '12345',
            'city' => 'Lieferstadt',
            'country' => 'Deutschland',
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->patch("/admin/willkommensbox-orders/{$order->id}/ship", [
            'tracking_number' => 'DHL1234567890',
        ]);

        $response->assertRedirect();
        $this->onAdmin(function () use ($order) {
            $fresh = $order->fresh();
            $this->assertNotNull($fresh->shipped_at);
            $this->assertSame('DHL1234567890', $fresh->tracking_number);
        });

        $detail = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");
        $detail->assertOk();
        $detail->assertSee('Versendet am');
        $detail->assertSee('DHL1234567890');
        $detail->assertDontSee('Als versendet markieren');
    }

    public function test_admin_can_mark_a_willkommensbox_order_as_shipped_without_a_tracking_number(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantWillkommensbox($tenant, $learner);
        $order = $this->onAdmin(fn () => WillkommensboxOrder::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'street' => 'Lieferstraße 1',
            'postal_code' => '12345',
            'city' => 'Lieferstadt',
            'country' => 'Deutschland',
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->patch("/admin/willkommensbox-orders/{$order->id}/ship", []);

        $response->assertRedirect();
        $this->onAdmin(function () use ($order) {
            $fresh = $order->fresh();
            $this->assertNotNull($fresh->shipped_at);
            $this->assertNull($fresh->tracking_number);
        });
    }

    /**
     * Bug-Review F5: im gesamten Ablauf von Praxis-Anmeldung, Terminen und
     * Willkommensbox wurde bisher keine Mail verschickt -- der Lerner
     * bemerkte den Versand nur, wenn er von selbst wieder auf die
     * Willkommensbox-Seite sah.
     */
    public function test_marking_a_willkommensbox_order_as_shipped_notifies_the_learner_by_mail(): void
    {
        Notification::fake();

        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantWillkommensbox($tenant, $learner);
        $order = $this->onAdmin(fn () => WillkommensboxOrder::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'street' => 'Lieferstraße 1',
            'postal_code' => '12345',
            'city' => 'Lieferstadt',
            'country' => 'Deutschland',
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->patch("/admin/willkommensbox-orders/{$order->id}/ship", [
            'tracking_number' => 'DHL1234567890',
        ]);

        $response->assertRedirect();

        Notification::assertSentTo($learner, WillkommensboxOrderShipped::class);
    }

    /**
     * Bug-Review (Willkommensbox -- Doppelklick auf "Versendet" schickt
     * zwei Mails): ship() lief bisher unbedingt -- ein zweiter Request auf
     * eine bereits versendete Bestellung (Doppelklick oder zwei nahezu
     * gleichzeitige Requests) aktualisierte shipped_at erneut und
     * verschickte die Versandbestätigung ein zweites Mal.
     */
    public function test_shipping_an_already_shipped_order_again_does_not_send_a_second_mail(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantWillkommensbox($tenant, $learner);
        $order = $this->onAdmin(fn () => WillkommensboxOrder::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'street' => 'Lieferstraße 1',
            'postal_code' => '12345',
            'city' => 'Lieferstadt',
            'country' => 'Deutschland',
        ]));

        $first = $this->actingAsInTenant($admin, $tenant)->patch("/admin/willkommensbox-orders/{$order->id}/ship", [
            'tracking_number' => 'DHL1234567890',
        ]);
        $first->assertRedirect();
        $firstShippedAt = $this->onAdmin(fn () => $order->fresh()->shipped_at);

        Notification::fake();

        $second = $this->actingAsInTenant($admin, $tenant)->patch("/admin/willkommensbox-orders/{$order->id}/ship", [
            'tracking_number' => 'DHL-ZWEITER-VERSUCH',
        ]);

        $second->assertRedirect();
        Notification::assertNothingSent();
        $this->onAdmin(function () use ($order, $firstShippedAt) {
            $fresh = $order->fresh();
            $this->assertSame($firstShippedAt->format('Y-m-d H:i:s'), $fresh->shipped_at->format('Y-m-d H:i:s'));
            $this->assertSame('DHL1234567890', $fresh->tracking_number);
        });
    }

    public function test_admin_cannot_ship_a_willkommensbox_order_of_another_tenant(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $adminA = $this->createTenantUser($tenantA, 'owner');
        $learnerB = $this->createTenantUser($tenantB, 'learner');
        $this->grantWillkommensbox($tenantB, $learnerB);
        $order = $this->onAdmin(fn () => WillkommensboxOrder::create([
            'tenant_id' => $tenantB->id,
            'user_id' => $learnerB->id,
            'street' => 'Lieferstraße 1',
            'postal_code' => '12345',
            'city' => 'Lieferstadt',
            'country' => 'Deutschland',
        ]));

        $response = $this->actingAsInTenant($adminA, $tenantA)->patch("/admin/willkommensbox-orders/{$order->id}/ship", [
            'tracking_number' => 'SOLLTE-NICHT-ANKOMMEN',
        ]);

        $response->assertNotFound();
        $this->onAdmin(function () use ($order) {
            $this->assertNull($order->fresh()->shipped_at);
        });
    }
}
