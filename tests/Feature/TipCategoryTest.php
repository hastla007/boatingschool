<?php

namespace Tests\Feature;

use App\Models\Tip;
use App\Models\TipCategory;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Kategorien für "Tipps & Tricks" sind eine eigene Verwaltungsebene im
 * Superadmin: unabhängig von einzelnen Beiträgen anlegbar, umbenennbar und
 * löschbar -- eine Kategorie mit zugeordneten Tipps darf aber nicht
 * gelöscht werden, um Beiträge nicht kategorielos zurückzulassen.
 */
class TipCategoryTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdTipIds = [];

    private array $createdCategoryIds = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach ($this->createdTipIds as $id) {
                Tip::where('id', $id)->delete();
            }
            foreach ($this->createdCategoryIds as $id) {
                TipCategory::where('id', $id)->delete();
            }
        });

        parent::tearDown();
    }

    private function createTestCategory(string $name = 'E2E Knoten'): TipCategory
    {
        return $this->onAdmin(function () use ($name) {
            $category = TipCategory::firstOrCreate(['name' => $name]);
            $this->createdCategoryIds[] = $category->id;

            return $category;
        });
    }

    public function test_superadmin_can_create_a_category(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->post('/superadmin/tips/categories', [
            'name' => 'E2E Sicherheit',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tip_category', ['name' => 'E2E Sicherheit']);

        $category = TipCategory::where('name', 'E2E Sicherheit')->first();
        $this->createdCategoryIds[] = $category->id;
    }

    public function test_superadmin_can_rename_a_category(): void
    {
        $superadmin = $this->createSuperAdmin();
        $category = $this->createTestCategory('E2E Alt');

        $response = $this->actingAs($superadmin)->patch("/superadmin/tips/categories/{$category->id}", [
            'name' => 'E2E Neu',
            'sort_order' => 5,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tip_category', ['id' => $category->id, 'name' => 'E2E Neu', 'sort_order' => 5]);
    }

    public function test_superadmin_cannot_create_two_categories_with_the_same_name(): void
    {
        $superadmin = $this->createSuperAdmin();
        $this->createTestCategory('E2E Doppelt');

        $response = $this->actingAs($superadmin)->post('/superadmin/tips/categories', [
            'name' => 'E2E Doppelt',
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_superadmin_can_delete_an_unused_category(): void
    {
        $superadmin = $this->createSuperAdmin();
        $category = $this->createTestCategory('E2E Ungenutzt');

        $response = $this->actingAs($superadmin)->delete("/superadmin/tips/categories/{$category->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('tip_category', ['id' => $category->id]);
    }

    public function test_superadmin_cannot_delete_a_category_still_used_by_tips(): void
    {
        $superadmin = $this->createSuperAdmin();
        $category = $this->createTestCategory('E2E Belegt');
        $tip = $this->onAdmin(function () use ($category) {
            $tip = Tip::create([
                'category_id' => $category->id,
                'title' => 'E2E Tipp in Kategorie',
                'body' => 'Inhalt.',
                'active' => true,
            ]);
            $this->createdTipIds[] = $tip->id;

            return $tip;
        });

        $response = $this->actingAs($superadmin)->delete("/superadmin/tips/categories/{$category->id}");

        $response->assertSessionHasErrors('category');
        $this->assertDatabaseHas('tip_category', ['id' => $category->id]);
        $this->assertDatabaseHas('tip', ['id' => $tip->id]);
    }
}
