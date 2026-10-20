<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Models\Product;
use App\Models\ProductPurchase;
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
        $response->assertSee('Hafenstraße 5');
        $response->assertSee('20457 Hamburg');
        $response->assertSee('Deutschland');
        $response->assertSee('15.06.1995');
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
}
