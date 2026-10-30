<?php

namespace Tests\Feature;

use App\Models\AddonTemplate;
use App\Models\Pruefungsausschuss;
use App\Models\Tip;
use App\Models\TipCategory;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review BB17: sort_order ist in addon_template, pruefungsausschuss,
 * tip und tip_category eine Postgres integer-Spalte (Wertebereich bis
 * 2147483647), die Validierung ließ bislang aber jeden PHP-Integer zu
 * (bis PHP_INT_MAX, auf 64-Bit-Systemen weit über dem Postgres-Bereich).
 * Eine zu große Zahl erreichte dadurch ungeprüft die Datenbank und löste
 * dort eine "integer out of range"-Exception aus -- eine unbehandelte
 * QueryException, also eine Fehlerseite statt einer Validierungsmeldung.
 */
class BB17SortOrderValidationTest extends TestCase
{
    use InteractsWithTenants;

    private const TOO_LARGE = '99999999999';

    public function test_addon_template_store_rejects_oversized_sort_order(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->post('/superadmin/addon-templates', [
            'title' => 'E2E Vorlage',
            'headline' => 'E2E Headline',
            'cta_label' => 'E2E CTA',
            'sort_order' => self::TOO_LARGE,
        ]);

        $response->assertSessionHasErrors('sort_order');
        $this->assertDatabaseMissing('addon_template', ['title' => 'E2E Vorlage']);
    }

    public function test_pruefungsausschuss_store_rejects_oversized_sort_order(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->post('/superadmin/pruefungsausschuesse', [
            'kommission' => 'E2E Kommission',
            'sort_order' => self::TOO_LARGE,
        ]);

        $response->assertSessionHasErrors('sort_order');
        $this->assertDatabaseMissing('pruefungsausschuss', ['kommission' => 'E2E Kommission']);
    }

    public function test_tip_category_update_rejects_oversized_sort_order(): void
    {
        $superadmin = $this->createSuperAdmin();
        $category = $this->onAdmin(fn () => TipCategory::firstOrCreate(['name' => 'E2E BB17 Kategorie']));

        $response = $this->actingAs($superadmin)->patch("/superadmin/tips/categories/{$category->id}", [
            'name' => 'E2E BB17 Kategorie',
            'sort_order' => self::TOO_LARGE,
        ]);

        $response->assertSessionHasErrors('sort_order');

        $this->onAdmin(fn () => TipCategory::where('id', $category->id)->delete());
        $this->cleanUpCreatedTenantsAndUsers();
    }

    public function test_tip_store_rejects_oversized_sort_order(): void
    {
        $superadmin = $this->createSuperAdmin();
        $category = $this->onAdmin(fn () => TipCategory::firstOrCreate(['name' => 'E2E BB17 Tipp-Kategorie']));

        $response = $this->actingAs($superadmin)->post('/superadmin/tips', [
            'category_id' => $category->id,
            'title' => 'E2E Tipp',
            'body' => 'Inhalt.',
            'sort_order' => self::TOO_LARGE,
        ]);

        $response->assertSessionHasErrors('sort_order');
        $this->assertDatabaseMissing('tip', ['title' => 'E2E Tipp']);

        $this->onAdmin(fn () => TipCategory::where('id', $category->id)->delete());
        $this->cleanUpCreatedTenantsAndUsers();
    }

    public function test_addon_template_store_still_accepts_a_normal_sort_order(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->post('/superadmin/addon-templates', [
            'title' => 'E2E Vorlage Normal',
            'headline' => 'E2E Headline',
            'cta_label' => 'E2E CTA',
            'sort_order' => 5,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('addon_template', ['title' => 'E2E Vorlage Normal', 'sort_order' => 5]);

        $this->onAdmin(fn () => AddonTemplate::where('title', 'E2E Vorlage Normal')->delete());
    }
}
