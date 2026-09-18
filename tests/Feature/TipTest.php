<?php

namespace Tests\Feature;

use App\Models\Tip;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * "Tipps & Tricks" sind plattformweite, redaktionelle Beiträge (kein
 * tenant_id) -- im Superadmin per Editor gepflegt und für alle Lernenden
 * über einen eigenen Menüpunkt neben "Kurse" sichtbar.
 */
class TipTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdTipIds = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach ($this->createdTipIds as $id) {
                Tip::where('id', $id)->delete();
            }
        });

        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    private function createTestTip(array $overrides = []): Tip
    {
        return $this->onAdmin(function () use ($overrides) {
            $tip = Tip::create(array_merge([
                'category' => 'Knoten',
                'title' => 'E2E Testtipp',
                'body' => 'Ein hilfreicher Tipp.',
                'active' => true,
            ], $overrides));
            $this->createdTipIds[] = $tip->id;

            return $tip;
        });
    }

    public function test_superadmin_can_create_a_tip(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->post('/superadmin/tips', [
            'category' => 'Wetter',
            'title' => 'E2E Neuer Tipp',
            'body' => 'Immer den Wetterbericht prüfen.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tip', ['category' => 'Wetter', 'title' => 'E2E Neuer Tipp', 'active' => true]);

        $tip = Tip::where('title', 'E2E Neuer Tipp')->first();
        $this->createdTipIds[] = $tip->id;
    }

    public function test_superadmin_can_update_a_tip(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tip = $this->createTestTip();

        $response = $this->actingAs($superadmin)->patch("/superadmin/tips/{$tip->id}", [
            'category' => 'Knoten',
            'title' => 'E2E Testtipp (aktualisiert)',
            'body' => 'Neuer Inhalt.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tip', ['id' => $tip->id, 'title' => 'E2E Testtipp (aktualisiert)', 'body' => 'Neuer Inhalt.']);
    }

    public function test_superadmin_can_toggle_a_tip_active(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tip = $this->createTestTip();

        $response = $this->actingAs($superadmin)->post("/superadmin/tips/{$tip->id}/toggle-active");

        $response->assertRedirect();
        $this->assertDatabaseHas('tip', ['id' => $tip->id, 'active' => false]);
    }

    public function test_superadmin_can_delete_a_tip(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tip = $this->createTestTip();

        $response = $this->actingAs($superadmin)->delete("/superadmin/tips/{$tip->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('tip', ['id' => $tip->id]);
    }

    public function test_learners_see_active_tips_grouped_by_category(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->createTestTip(['category' => 'Knoten', 'title' => 'E2E Palstek', 'body' => 'So bindest du einen Palstek.']);
        $this->createTestTip(['category' => 'Wetter', 'title' => 'E2E Wolken lesen', 'body' => 'Cumuluswolken erkennen.']);

        $response = $this->actingAsInTenant($learner, $tenant)->get('/tipps-tricks');

        $response->assertOk();
        $response->assertSee('Tipps & Tricks', false);
        $response->assertSee('Knoten');
        $response->assertSee('E2E Palstek');
        $response->assertSee('Wetter');
        $response->assertSee('E2E Wolken lesen');
    }

    public function test_learners_do_not_see_deactivated_tips(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->createTestTip(['title' => 'E2E Verstecktes Tipp', 'active' => false]);

        $response = $this->actingAsInTenant($learner, $tenant)->get('/tipps-tricks');

        $response->assertOk();
        $response->assertDontSee('E2E Verstecktes Tipp');
    }

    public function test_tips_menu_link_is_shown_in_the_main_navigation(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Tipps & Tricks', false);
        $response->assertSee(route('tips.index'), false);
    }
}
