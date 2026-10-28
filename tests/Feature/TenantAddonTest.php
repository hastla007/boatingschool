<?php

namespace Tests\Feature;

use App\Models\AddonTemplate;
use App\Models\TenantAddon;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bootsschulen aktivieren vom Superadmin vorgefertigte Addon-Vorlagen
 * (siehe AddonTemplateTest) für sich selbst und befüllen sie mit ihren
 * eigenen Daten (Gutscheincode, Preis, Trigger-Kurs/-Fortschritt, Link) --
 * siehe admin/tabs/addons.blade.php und AddonController.
 */
class TenantAddonTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdTemplateIds = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach ($this->createdTemplateIds as $id) {
                AddonTemplate::where('id', $id)->delete();
            }
        });

        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    private function createTestTemplate(): AddonTemplate
    {
        return $this->onAdmin(function () {
            $template = AddonTemplate::create([
                'title' => 'E2E Großes Paket',
                'badge_text' => 'BESTSELLER',
                'stat_text' => '81% wählen diese Option',
                'headline' => 'Sichere dir jetzt das große Paket',
                'bullets' => ['Vorteil 1', 'Vorteil 2'],
                'cta_label' => 'Jetzt sichern',
                'active' => true,
            ]);
            $this->createdTemplateIds[] = $template->id;

            return $template;
        });
    }

    public function test_a_school_admin_can_activate_and_configure_an_addon(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $template = $this->createTestTemplate();
        $course = $this->existingCourse('SRC');

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/addons', [
            'addons' => [
                $template->id => [
                    'active' => '1',
                    'coupon_code' => 'SOMMER25',
                    'price_label' => '149 €',
                    'discount_percent' => '20',
                    'trigger_course_id' => $course->id,
                    'trigger_percent' => '30',
                    'offer_url' => 'https://shop.example.test/grosses-paket',
                ],
            ],
        ]);

        $response->assertRedirect();
        $this->asTenant($tenant, function () use ($tenant, $template, $course) {
            $this->assertDatabaseHas('tenant_addon', [
                'tenant_id' => $tenant->id,
                'addon_template_id' => $template->id,
                'active' => true,
                'coupon_code' => 'SOMMER25',
                'price_label' => '149 €',
                'discount_percent' => 20,
                'trigger_course_id' => $course->id,
                'trigger_percent' => 30,
                'offer_url' => 'https://shop.example.test/grosses-paket',
            ]);
        });
    }

    /**
     * Bug-Review (Query-Parameter-Validierung): die Schlüssel des
     * addons-Arrays (Vorlagen-IDs) kamen bisher ungeprüft direkt in ein
     * where(...)/insert gegen die uuid-Spalte tenant_addon.
     * addon_template_id -- ein manipulierter, nicht-UUID-förmiger
     * Schlüssel löste "invalid input syntax for type uuid" aus, ein
     * UUID-förmiger, aber nicht existierender Schlüssel eine verletzte
     * Fremdschlüssel-Zwangsbedingung. Beides endete als unbehandelte
     * QueryException mit HTTP 500.
     */
    public function test_a_manipulated_non_uuid_addon_key_is_ignored_instead_of_a_500(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/addons', [
            'addons' => [
                'not-a-real-uuid' => ['active' => '1'],
            ],
        ]);

        $response->assertRedirect();
        $this->asTenant($tenant, function () use ($tenant) {
            $this->assertSame(0, TenantAddon::where('tenant_id', $tenant->id)->count());
        });
    }

    public function test_a_manipulated_uuid_shaped_but_nonexistent_addon_key_is_ignored_instead_of_a_500(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $bogusId = (string) Str::uuid();

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/addons', [
            'addons' => [
                $bogusId => ['active' => '1'],
            ],
        ]);

        $response->assertRedirect();
        $this->asTenant($tenant, function () use ($tenant, $bogusId) {
            $this->assertDatabaseMissing('tenant_addon', [
                'tenant_id' => $tenant->id,
                'addon_template_id' => $bogusId,
            ]);
        });
    }

    public function test_discount_percent_is_optional_and_defaults_to_null(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $template = $this->createTestTemplate();
        $course = $this->existingCourse('SRC');

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/addons', [
            'addons' => [
                $template->id => [
                    'active' => '1',
                    'price_label' => '149 €',
                    'trigger_course_id' => $course->id,
                    'trigger_percent' => '30',
                    'offer_url' => 'https://shop.example.test/grosses-paket',
                ],
            ],
        ]);

        $response->assertRedirect();
        $this->asTenant($tenant, function () use ($tenant, $template) {
            $this->assertDatabaseHas('tenant_addon', [
                'tenant_id' => $tenant->id,
                'addon_template_id' => $template->id,
                'discount_percent' => null,
            ]);
        });
    }

    public function test_a_school_admin_can_override_the_templates_bullets(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $template = $this->createTestTemplate();
        $course = $this->existingCourse('SRC');

        $this->actingAsInTenant($admin, $tenant)->patch('/admin/addons', [
            'addons' => [
                $template->id => [
                    'active' => '1',
                    'bullets' => "Eigener Punkt 1\n\nEigener Punkt 2\n",
                    'trigger_course_id' => $course->id,
                    'trigger_percent' => '30',
                    'offer_url' => 'https://shop.example.test/grosses-paket',
                ],
            ],
        ]);

        $tenantAddon = $this->onAdmin(fn () => TenantAddon::where('tenant_id', $tenant->id)->where('addon_template_id', $template->id)->first());

        $this->assertSame(['Eigener Punkt 1', 'Eigener Punkt 2'], $tenantAddon->bullets);
        $this->assertSame(['Eigener Punkt 1', 'Eigener Punkt 2'], $tenantAddon->effectiveBullets());
    }

    public function test_leaving_bullets_empty_falls_back_to_the_templates_bullets(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $template = $this->createTestTemplate();
        $course = $this->existingCourse('SRC');

        $this->actingAsInTenant($admin, $tenant)->patch('/admin/addons', [
            'addons' => [
                $template->id => [
                    'active' => '1',
                    'trigger_course_id' => $course->id,
                    'trigger_percent' => '30',
                    'offer_url' => 'https://shop.example.test/grosses-paket',
                ],
            ],
        ]);

        $tenantAddon = $this->onAdmin(fn () => TenantAddon::where('tenant_id', $tenant->id)->where('addon_template_id', $template->id)->first());

        $this->assertNull($tenantAddon->bullets);
        $this->assertSame(['Vorteil 1', 'Vorteil 2'], $tenantAddon->effectiveBullets());
    }

    public function test_deactivating_an_addon_removes_its_configuration(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $template = $this->createTestTemplate();
        $course = $this->existingCourse('SRC');

        $this->actingAsInTenant($admin, $tenant)->patch('/admin/addons', [
            'addons' => [
                $template->id => [
                    'active' => '1',
                    'trigger_course_id' => $course->id,
                    'trigger_percent' => '30',
                    'offer_url' => 'https://shop.example.test/grosses-paket',
                ],
            ],
        ]);

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/addons', [
            'addons' => [
                $template->id => ['active' => '0'],
            ],
        ]);

        $response->assertRedirect();
        $this->asTenant($tenant, fn () => $this->assertDatabaseMissing('tenant_addon', [
            'tenant_id' => $tenant->id,
            'addon_template_id' => $template->id,
        ]));
    }

    /**
     * Bug-Review F2: AddonController::update() iterierte bisher über ALLE
     * Vorlagen (AddonTemplate::pluck('id')), nicht nur die aktiven -- das
     * Formular (siehe admin/tabs/addons.blade.php) rendert aber nur Zeilen
     * für aktive Vorlagen (AdminDashboardController). Deaktivierte der
     * Superadmin eine Vorlage, fehlte ihre ID im nächsten Request komplett,
     * $active fiel auf false zurück, und die eigene Konfiguration der
     * Bootsschule (Gutscheincode, Preis, Bullets, Trigger, Link) wurde
     * beim NÄCHSTEN Speichern gelöscht -- unabhängig davon, ob die Schule
     * überhaupt etwas an diesem Addon ändern wollte.
     */
    public function test_disabling_a_template_in_superadmin_does_not_delete_the_tenants_existing_addon_configuration(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $template = $this->createTestTemplate();
        $course = $this->existingCourse('SRC');

        $this->actingAsInTenant($admin, $tenant)->patch('/admin/addons', [
            'addons' => [
                $template->id => [
                    'active' => '1',
                    'coupon_code' => 'SOMMER25',
                    'trigger_course_id' => $course->id,
                    'trigger_percent' => '30',
                    'offer_url' => 'https://shop.example.test/grosses-paket',
                ],
            ],
        ])->assertRedirect();

        // Superadmin deaktiviert die Vorlage -- das Formular zeigt sie
        // danach gar nicht mehr an (AdminDashboardController filtert auf
        // active=true), ein erneutes Speichern enthält deshalb keine Zeile
        // mehr dafür.
        $this->onAdmin(fn () => $template->update(['active' => false]));

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/addons', ['addons' => []]);
        $response->assertRedirect();

        $this->asTenant($tenant, function () use ($tenant, $template) {
            $this->assertDatabaseHas('tenant_addon', [
                'tenant_id' => $tenant->id,
                'addon_template_id' => $template->id,
                'active' => true,
                'coupon_code' => 'SOMMER25',
            ]);
        });
    }

    public function test_addons_tab_lists_active_templates(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $this->createTestTemplate();

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=addons');

        $response->assertOk();
        $response->assertSee('E2E Großes Paket');
    }

    /**
     * Bug-Review R4-I: bei fehlgeschlagener Validierung liefert old() den
     * ROHEN, unveränderten Submit-Wert zurück -- der Default-Wert im
     * x-data-Ausdruck für "active" war bisher nur eine Attrappe ('true'/
     * 'false' als Text), old() selbst floss aber direkt, unescaped, in
     * diesen JS-String-Kontext ein. addons.*.active ist zwar mit
     * 'boolean' validiert, ein ungültiger Wert löst aber genau den
     * Validierungsfehler aus, der old() erst befüllt (Self-XSS, analog zu
     * Bug-Review R4-3 beim Collection-Namen).
     */
    public function test_an_invalid_active_value_is_not_reflected_unescaped_into_the_addons_tab(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $template = $this->createTestTemplate();

        $maliciousValue = '1}; alert(document.cookie); //';

        $this->actingAsInTenant($admin, $tenant)->patch('/admin/addons', [
            'addons' => [
                $template->id => [
                    'active' => $maliciousValue,
                ],
            ],
        ])->assertSessionHasErrors("addons.{$template->id}.active");

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=addons');
        $response->assertOk();

        $html = $response->getContent();

        // Die rohe, für den JS-Ausdruck gefährliche Eingabe darf an KEINER
        // Stelle unverändert im Markup auftauchen.
        $this->assertStringNotContainsString($maliciousValue, $html);

        // Stattdessen muss filter_var() den ungültigen Wert defensiv auf
        // false zwingen, bevor json_encode() ihn als reines Boolean-Literal
        // einbettet.
        $this->assertStringContainsString('active: false', $html);
    }

    public function test_addon_configuration_is_isolated_per_tenant(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $adminA = $this->createTenantUser($tenantA, 'owner');
        $template = $this->createTestTemplate();
        $course = $this->existingCourse('SRC');

        $this->actingAsInTenant($adminA, $tenantA)->patch('/admin/addons', [
            'addons' => [
                $template->id => [
                    'active' => '1',
                    'coupon_code' => 'NUR-A',
                    'trigger_course_id' => $course->id,
                    'trigger_percent' => '30',
                    'offer_url' => 'https://shop.example.test/a',
                ],
            ],
        ]);

        $this->asTenant($tenantA, function () use ($tenantA) {
            $this->assertDatabaseHas('tenant_addon', ['tenant_id' => $tenantA->id, 'coupon_code' => 'NUR-A']);
        });
        $this->onAdmin(fn () => $this->assertDatabaseMissing('tenant_addon', ['tenant_id' => $tenantB->id, 'addon_template_id' => $template->id]));
    }
}
