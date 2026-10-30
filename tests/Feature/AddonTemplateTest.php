<?php

namespace Tests\Feature;

use App\Models\AddonTemplate;
use App\Models\TenantAddon;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Addon-Vorlagen (siehe tenant_addon) sind plattformweite, im Superadmin
 * gepflegte Upsell-Banner-Texte (Titel, Badge, Stat, Headline, Bullets) --
 * jede Bootsschule aktiviert/konfiguriert sie für sich selbst (siehe
 * AddonTab-Tests), die Vorlage selbst trägt keine mandantenspezifischen
 * Felder wie Preis, Coupon oder Link.
 */
class AddonTemplateTest extends TestCase
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

    private function createTestTemplate(array $overrides = []): AddonTemplate
    {
        return $this->onAdmin(function () use ($overrides) {
            $template = AddonTemplate::create(array_merge([
                'title' => 'E2E Großes Paket',
                'badge_text' => 'BESTSELLER',
                'stat_text' => '81% wählen diese Option',
                'headline' => 'Sichere dir jetzt das große Paket',
                'bullets' => ['Vorteil 1', 'Vorteil 2'],
                'cta_label' => 'Jetzt sichern',
                'active' => true,
            ], $overrides));
            $this->createdTemplateIds[] = $template->id;

            return $template;
        });
    }

    public function test_superadmin_can_create_an_addon_template(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->post('/superadmin/addon-templates', [
            'title' => 'E2E Neue Vorlage',
            'badge_text' => 'BESTSELLER',
            'stat_text' => '81% wählen diese Option',
            'headline' => 'Sichere dir jetzt das große Paket',
            'bullets' => "Vorteil 1\nVorteil 2",
            'cta_label' => 'Jetzt sichern',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('addon_template', ['title' => 'E2E Neue Vorlage', 'active' => true]);

        $template = AddonTemplate::where('title', 'E2E Neue Vorlage')->first();
        $this->createdTemplateIds[] = $template->id;
        $this->assertSame(['Vorteil 1', 'Vorteil 2'], $template->bullets);
    }

    public function test_superadmin_can_update_an_addon_template(): void
    {
        $superadmin = $this->createSuperAdmin();
        $template = $this->createTestTemplate();

        $response = $this->actingAs($superadmin)->patch("/superadmin/addon-templates/{$template->id}", [
            'title' => 'E2E Großes Paket (aktualisiert)',
            'headline' => $template->headline,
            'bullets' => 'Neuer Vorteil',
            'cta_label' => $template->cta_label,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('addon_template', ['id' => $template->id, 'title' => 'E2E Großes Paket (aktualisiert)']);
        $this->assertSame(['Neuer Vorteil'], $template->fresh()->bullets);
    }

    public function test_superadmin_can_toggle_an_addon_template_active(): void
    {
        $superadmin = $this->createSuperAdmin();
        $template = $this->createTestTemplate();

        $response = $this->actingAs($superadmin)->post("/superadmin/addon-templates/{$template->id}/toggle-active");

        $response->assertRedirect();
        $this->assertDatabaseHas('addon_template', ['id' => $template->id, 'active' => false]);
    }

    public function test_superadmin_can_delete_an_addon_template(): void
    {
        $superadmin = $this->createSuperAdmin();
        $template = $this->createTestTemplate();

        $response = $this->actingAs($superadmin)->delete("/superadmin/addon-templates/{$template->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('addon_template', ['id' => $template->id]);
    }

    /**
     * Bug-Review BB19: tenant_addon.addon_template_id kaskadiert beim
     * Löschen der Vorlage -- ohne Schutz riss das stillschweigend auch die
     * individuelle Konfiguration (Coupon, Preistext, Trigger-Kurs/-Schwelle)
     * jeder Bootsschule mit, die das Addon aktiviert hatte.
     */
    public function test_superadmin_cannot_delete_a_template_still_configured_by_a_school(): void
    {
        $superadmin = $this->createSuperAdmin();
        $template = $this->createTestTemplate();
        $tenant = $this->createTestTenant();

        $this->onAdmin(fn () => TenantAddon::create([
            'tenant_id' => $tenant->id,
            'addon_template_id' => $template->id,
            'active' => true,
        ]));

        $response = $this->actingAs($superadmin)->delete("/superadmin/addon-templates/{$template->id}");

        $response->assertSessionHasErrors('addonTemplate');
        $this->assertDatabaseHas('addon_template', ['id' => $template->id]);
        $this->assertTrue($this->onAdmin(fn () => TenantAddon::where('tenant_id', $tenant->id)
            ->where('addon_template_id', $template->id)
            ->exists()));
    }

    public function test_addon_templates_index_lists_templates(): void
    {
        $superadmin = $this->createSuperAdmin();
        $this->createTestTemplate(['title' => 'E2E Sichtbare Vorlage']);

        $response = $this->actingAs($superadmin)->get('/superadmin/addon-templates');

        $response->assertOk();
        $response->assertSee('E2E Sichtbare Vorlage');
    }
}
