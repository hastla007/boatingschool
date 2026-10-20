<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Models\Product;
use App\Models\ProductPurchase;
use App\Models\WillkommensboxOrder;
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
        $this->onAdmin(function () use ($learner) {
            $fresh = $learner->fresh();
            $this->assertSame('Hafenstraße 5', $fresh->street);
            $this->assertSame('20457', $fresh->postal_code);
            $this->assertSame('Hamburg', $fresh->city);
            $this->assertSame('Deutschland', $fresh->country);
            $this->assertSame('1995-06-15', $fresh->birthdate->format('Y-m-d'));
            $this->assertTrue($fresh->hasCompleteRegistrationProfile());
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
        $this->onAdmin(function () use ($learner) {
            $fresh = $learner->fresh();
            $this->assertSame('Neue Straße 2', $fresh->street);
            $this->assertSame('20000', $fresh->postal_code);
            $this->assertSame('Neustadt', $fresh->city);
            $this->assertSame('Deutschland', $fresh->country);
            $this->assertSame('1991-02-02', $fresh->birthdate->format('Y-m-d'));
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
        $this->onAdmin(function () use ($learnerB) {
            $this->assertNull($learnerB->fresh()->city);
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
