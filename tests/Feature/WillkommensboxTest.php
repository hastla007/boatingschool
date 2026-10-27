<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\Module;
use App\Models\Product;
use App\Models\ProductPurchase;
use App\Models\Progress;
use App\Models\TenantUser;
use App\Models\WillkommensboxOrder;
use App\Notifications\WillkommensboxOrderPlaced;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bestellformular für die Willkommensbox (siehe WillkommensboxController,
 * WillkommensboxOrder) -- nur erreichbar, wenn der Nutzer "Lernplattform
 * (inkl. Willkommensbox)" eingelöst hat (siehe CouponManagementTest für die
 * Einlöse-Seite, die diese ProductPurchase erzeugt) UND den bootsschulweiten
 * Kursfortschritt (siehe TenantBranding::willkommensbox_threshold_percent)
 * erreicht hat.
 */
class WillkommensboxTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    private array $createdModuleIds = [];

    private array $createdQuestionIds = [];

    protected function tearDown(): void
    {
        // Tenants zuerst aufräumen (kaskadiert Entitlements weg), erst
        // danach die global geteilten Test-Kurse löschen -- sonst schlägt
        // das wegen der FK auf noch existierende Entitlements fehl.
        $this->cleanUpCreatedTenantsAndUsers();

        $this->onAdmin(function () {
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->whereKey($id)->delete();
            }
            foreach ($this->createdModuleIds as $id) {
                Module::whereKey($id)->delete();
            }
            foreach ($this->createdQuestionIds as $id) {
                ContentQuestion::whereKey($id)->delete();
            }
        });

        parent::tearDown();
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

    private function setThreshold($tenant, int $percent): void
    {
        $this->onAdmin(fn () => $tenant->branding->update(['willkommensbox_threshold_percent' => $percent]));
    }

    /** @return array{0: CourseDefinition, 1: array<int, string>} */
    private function makeIsolatedCourseWithQuestions(int $count): array
    {
        return $this->onAdmin(function () use ($count) {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-WB-'.uniqid(),
                'name' => 'Test-Kurs für Willkommensbox',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $module = Module::create([
                'code' => 'TEST-WB-MODULE-'.uniqid(),
                'name' => 'Testmodul',
                'version' => '1',
                'status' => 'published',
            ]);
            $this->createdModuleIds[] = $module->id;
            $course->modules()->attach($module->id, ['sort_order' => 1, 'required' => true]);

            $questionIds = [];
            for ($i = 0; $i < $count; $i++) {
                $question = ContentQuestion::create([
                    'content_id' => 'TEST-WB-Q-'.uniqid(),
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

    private function masterQuestions($tenant, $learner, array $questionIds): void
    {
        $this->onAdmin(function () use ($tenant, $learner, $questionIds) {
            foreach ($questionIds as $questionId) {
                Progress::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'user_id' => $learner->id, 'question_id' => $questionId],
                    ['learning_state' => 'gefestigt', 'mastery_score' => 1, 'attempt_count' => 1, 'correct_count' => 1]
                );
            }
        });
    }

    public function test_menu_link_is_hidden_without_the_product(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('Willkommensbox');
    }

    public function test_menu_link_is_shown_once_the_product_is_granted(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantWillkommensbox($tenant, $learner);

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Willkommensbox');
        $response->assertSee(route('willkommensbox.index', absolute: false), false);
    }

    public function test_page_is_forbidden_without_the_product(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/willkommensbox');

        $response->assertForbidden();
    }

    public function test_form_is_prefilled_from_the_profile_address(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner', [
            'street' => 'Profilstraße 9', 'postal_code' => '99999', 'city' => 'Profilstadt', 'country' => 'Deutschland',
        ]);
        $this->grantWillkommensbox($tenant, $learner);

        $response = $this->actingAsInTenant($learner, $tenant)->get('/willkommensbox');

        $response->assertOk();
        $response->assertSee('Profilstraße 9');
        $response->assertSee('99999');
        $response->assertSee('Profilstadt');
    }

    public function test_learner_can_order_the_willkommensbox(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantWillkommensbox($tenant, $learner);

        $response = $this->actingAsInTenant($learner, $tenant)->post('/willkommensbox', [
            'street' => 'Lieferstraße 1',
            'postal_code' => '12345',
            'city' => 'Lieferstadt',
            'country' => 'Deutschland',
            'note' => 'Bitte beim Nachbarn abgeben.',
        ]);

        $response->assertRedirect(route('willkommensbox.index'));
        $this->onAdmin(fn () => $this->assertDatabaseHas('willkommensbox_order', [
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'street' => 'Lieferstraße 1',
            'postal_code' => '12345',
            'city' => 'Lieferstadt',
            'country' => 'Deutschland',
            'note' => 'Bitte beim Nachbarn abgeben.',
        ]));

        $confirmation = $this->actingAsInTenant($learner, $tenant)->get('/willkommensbox');
        $confirmation->assertOk();
        $confirmation->assertSee('Lieferstraße 1');
        $confirmation->assertSee('bestellt');
    }

    /**
     * Bug-Review F5: im gesamten Ablauf von Praxis-Anmeldung, Terminen und
     * Willkommensbox wurde bisher keine Mail verschickt -- eine Bootsschule
     * bemerkte den Versandbedarf nur, wenn jemand aktiv in der
     * Teilnehmerliste nachsah.
     */
    public function test_ordering_the_willkommensbox_notifies_the_schools_admins_by_mail(): void
    {
        Notification::fake();

        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantWillkommensbox($tenant, $learner);

        $response = $this->actingAsInTenant($learner, $tenant)->post('/willkommensbox', [
            'street' => 'Lieferstraße 1',
            'postal_code' => '12345',
            'city' => 'Lieferstadt',
            'country' => 'Deutschland',
        ]);

        $response->assertRedirect(route('willkommensbox.index'));

        Notification::assertSentTo($owner, WillkommensboxOrderPlaced::class);
    }

    public function test_willkommensbox_cannot_be_ordered_twice(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantWillkommensbox($tenant, $learner);

        $this->actingAsInTenant($learner, $tenant)->post('/willkommensbox', [
            'street' => 'Erste Straße 1', 'postal_code' => '11111', 'city' => 'Erststadt', 'country' => 'Deutschland',
        ])->assertRedirect(route('willkommensbox.index'));

        $second = $this->actingAsInTenant($learner, $tenant)->post('/willkommensbox', [
            'street' => 'Zweite Straße 2', 'postal_code' => '22222', 'city' => 'Zweitstadt', 'country' => 'Deutschland',
        ]);

        $second->assertRedirect();
        $this->onAdmin(function () use ($tenant, $learner) {
            $this->assertSame(1, WillkommensboxOrder::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->count());
            $this->assertDatabaseHas('willkommensbox_order', ['tenant_id' => $tenant->id, 'user_id' => $learner->id, 'street' => 'Erste Straße 1']);
        });
    }

    public function test_required_fields_are_validated(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantWillkommensbox($tenant, $learner);

        $response = $this->actingAsInTenant($learner, $tenant)->post('/willkommensbox', []);

        $response->assertSessionHasErrors(['street', 'postal_code', 'city', 'country']);
    }

    public function test_order_is_forbidden_without_the_product(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->post('/willkommensbox', [
            'street' => 'Straße 1', 'postal_code' => '12345', 'city' => 'Stadt', 'country' => 'Deutschland',
        ]);

        $response->assertForbidden();
    }

    public function test_willkommensbox_is_isolated_per_tenant(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $learner = $this->createTenantUser($tenantA, 'learner');
        $this->onAdmin(fn () => TenantUser::create(['tenant_id' => $tenantB->id, 'user_id' => $learner->id, 'role' => 'learner', 'status' => 'active']));
        $this->grantWillkommensbox($tenantA, $learner);

        $responseA = $this->actingAsInTenant($learner, $tenantA)->get('/willkommensbox');
        $responseA->assertOk();

        $responseB = $this->actingAsInTenant($learner, $tenantB)->get('/willkommensbox');
        $responseB->assertForbidden();
    }

    public function test_page_shows_locked_state_below_the_progress_threshold(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantWillkommensbox($tenant, $learner);
        $this->setThreshold($tenant, 50);
        [$course, $questionIds] = $this->makeIsolatedCourseWithQuestions(4);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->masterQuestions($tenant, $learner, array_slice($questionIds, 0, 1));

        $response = $this->actingAsInTenant($learner, $tenant)->get('/willkommensbox');

        $response->assertOk();
        $response->assertSee('noch nicht alle Voraussetzungen');
        $response->assertDontSee('Jetzt bestellen');
    }

    public function test_page_shows_the_form_once_the_progress_threshold_is_reached(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantWillkommensbox($tenant, $learner);
        $this->setThreshold($tenant, 50);
        [$course, $questionIds] = $this->makeIsolatedCourseWithQuestions(4);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->masterQuestions($tenant, $learner, array_slice($questionIds, 0, 2));

        $response = $this->actingAsInTenant($learner, $tenant)->get('/willkommensbox');

        $response->assertOk();
        $response->assertSee('Jetzt bestellen');
    }

    public function test_order_is_forbidden_below_the_progress_threshold(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantWillkommensbox($tenant, $learner);
        $this->setThreshold($tenant, 50);
        [$course] = $this->makeIsolatedCourseWithQuestions(4);
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->post('/willkommensbox', [
            'street' => 'Straße 1', 'postal_code' => '12345', 'city' => 'Stadt', 'country' => 'Deutschland',
        ]);

        $response->assertForbidden();
        $this->onAdmin(fn () => $this->assertDatabaseMissing('willkommensbox_order', ['tenant_id' => $tenant->id, 'user_id' => $learner->id]));
    }

    public function test_default_threshold_of_zero_does_not_gate_the_order(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantWillkommensbox($tenant, $learner);

        $response = $this->actingAsInTenant($learner, $tenant)->get('/willkommensbox');

        $response->assertOk();
        $response->assertSee('Jetzt bestellen');
    }
}
