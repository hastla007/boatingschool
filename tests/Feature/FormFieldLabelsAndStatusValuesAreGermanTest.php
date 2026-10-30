<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Formular-Fehlermeldungen zeigen interne Feldnamen und
 * unübersetzte Enum-Werte): lang/de/validation.php kannte "admin_first_name"
 * & Co. nicht, Laravels Fallback zeigte dadurch den rohen, aus dem
 * Feldnamen gebildeten Text ("admin first name") statt eines deutschen
 * Labels. Mehrere Admin-/Superadmin-Views gaben außerdem Status-/
 * Rollen-Enumwerte ("active", "learner") direkt unübersetzt aus, siehe
 * App\Support\StatusLabels.
 */
class FormFieldLabelsAndStatusValuesAreGermanTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_creating_a_tenant_with_missing_fields_shows_german_labels_not_raw_field_names(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->post('/superadmin/tenants', [
            'slug' => '',
            'name' => '',
            'status' => 'active',
            'admin_first_name' => '',
            'admin_last_name' => '',
            'admin_email' => '',
        ]);

        $response->assertSessionHasErrors(['slug', 'name', 'admin_first_name', 'admin_last_name', 'admin_email']);

        $errors = session('errors');
        $this->assertStringContainsString('Vorname', $errors->first('admin_first_name'));
        $this->assertStringNotContainsString('admin first name', $errors->first('admin_first_name'));
        $this->assertStringContainsString('Nachname', $errors->first('admin_last_name'));
        $this->assertStringNotContainsString('admin last name', $errors->first('admin_last_name'));
        $this->assertStringContainsString('E-Mail-Adresse', $errors->first('admin_email'));
        $this->assertStringNotContainsString('admin email', $errors->first('admin_email'));
        $this->assertStringContainsString('Slug', $errors->first('slug'));
    }

    public function test_the_tenant_list_shows_a_german_status_label_not_the_raw_enum_value(): void
    {
        $superadmin = $this->createSuperAdmin();
        $this->createTestTenant();

        $response = $this->actingAs($superadmin)->get(route('superadmin.tenants.index'));

        $response->assertOk();
        $response->assertSee('aktiv');
        $response->assertDontSee('>active<', false);
    }

    public function test_the_user_list_shows_german_status_and_role_labels_not_raw_enum_values(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAs($superadmin)->get(route('superadmin.users.index'));

        $response->assertOk();
        $response->assertSee('Lerner');
        $response->assertDontSee('(learner)', false);
    }
}
