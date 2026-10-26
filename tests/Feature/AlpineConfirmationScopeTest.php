<?php

namespace Tests\Feature;

use App\Models\AddonTemplate;
use App\Models\PraxisFormTemplate;
use App\Models\Pruefungsausschuss;
use App\Models\Tip;
use App\Models\TipCategory;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review R4-2: mehrere Superadmin-Seiten verwendeten @submit="...
 * confirm(...)..." (Löschbestätigung) bzw. @change="..." (Coupon-Export,
 * Auto-Submit der Filter) OHNE dass die Seite irgendwo einen x-data-Bereich
 * besaß. Alpine.js initialisiert Direktiven (@click/@submit/@change/...)
 * nur innerhalb des Teilbaums eines x-data-Elements -- ohne einen solchen
 * Vorfahren bleibt die Direktive ein wirkungsloses, rohes HTML-Attribut.
 * Für eine Löschbestätigung heißt das: das Formular wird beim Klick auf
 * "Löschen" sofort abgeschickt, OHNE jemals confirm() aufzurufen.
 */
class AlpineConfirmationScopeTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCategoryIds = [];

    private array $createdTipIds = [];

    private array $createdTemplateIds = [];

    private array $createdPruefungsausschussIds = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach ($this->createdTipIds as $id) {
                Tip::where('id', $id)->delete();
            }
            foreach ($this->createdCategoryIds as $id) {
                TipCategory::where('id', $id)->delete();
            }
            foreach ($this->createdTemplateIds as $id) {
                AddonTemplate::where('id', $id)->delete();
                PraxisFormTemplate::where('id', $id)->delete();
            }
            foreach ($this->createdPruefungsausschussIds as $id) {
                Pruefungsausschuss::where('id', $id)->delete();
            }
        });

        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    /** Die confirm()-Direktive muss innerhalb (also NACH dem öffnenden Tag) eines x-data-Bereichs liegen. */
    private function assertConfirmIsInsideAnAlpineScope(string $html, string $needle): void
    {
        $this->assertStringContainsString($needle, $html, 'Erwarteter Direktiven-Aufruf nicht im HTML gefunden.');
        $this->assertStringContainsString('x-data', $html, 'Seite besitzt gar keinen x-data-Bereich -- die Direktive wird von Alpine nie initialisiert.');

        $xDataPos = strpos($html, 'x-data');
        $needlePos = strpos($html, $needle);
        $this->assertLessThan($needlePos, $xDataPos, 'x-data steht erst NACH der Direktive -- sie liegt außerhalb jedes Alpine-Scopes.');
    }

    public function test_pruefungsausschuss_delete_confirmation_is_inside_an_alpine_scope(): void
    {
        $superadmin = $this->createSuperAdmin();
        $pa = $this->onAdmin(function () {
            $pa = Pruefungsausschuss::create(['kommission' => 'E2E Kommission', 'standorttyp' => 'see']);
            $this->createdPruefungsausschussIds[] = $pa->id;

            return $pa;
        });

        $response = $this->actingAs($superadmin)->get('/superadmin/pruefungsausschuesse');

        $response->assertOk();
        $this->assertConfirmIsInsideAnAlpineScope($response->getContent(), "confirm('Diesen Prüfungsausschuss wirklich löschen?");
    }

    public function test_tip_category_delete_confirmation_is_inside_an_alpine_scope(): void
    {
        $superadmin = $this->createSuperAdmin();
        $category = $this->onAdmin(function () {
            $category = TipCategory::firstOrCreate(['name' => 'E2E Knoten']);
            $this->createdCategoryIds[] = $category->id;

            return $category;
        });

        $response = $this->actingAs($superadmin)->get('/superadmin/tips/categories');

        $response->assertOk();
        $this->assertConfirmIsInsideAnAlpineScope($response->getContent(), "confirm('Diese Kategorie wirklich löschen?");
    }

    public function test_tip_delete_confirmation_is_inside_an_alpine_scope(): void
    {
        $superadmin = $this->createSuperAdmin();
        $this->onAdmin(function () {
            $category = TipCategory::firstOrCreate(['name' => 'E2E Knoten']);
            $this->createdCategoryIds[] = $category->id;

            $tip = Tip::create([
                'category_id' => $category->id,
                'title' => 'E2E Testtipp',
                'body' => 'Ein hilfreicher Tipp.',
            ]);
            $this->createdTipIds[] = $tip->id;
        });

        $response = $this->actingAs($superadmin)->get('/superadmin/tips');

        $response->assertOk();
        $this->assertConfirmIsInsideAnAlpineScope($response->getContent(), "confirm('Diesen Tipp wirklich löschen?");
    }

    public function test_praxis_form_template_delete_confirmation_is_inside_an_alpine_scope(): void
    {
        $superadmin = $this->createSuperAdmin();
        $template = $this->onAdmin(function () {
            $template = PraxisFormTemplate::create([
                'title' => 'E2E Formular',
                'min_progress_percent' => 50,
                'fields' => [],
                'course_ids' => [],
                'active' => true,
            ]);
            $this->createdTemplateIds[] = $template->id;

            return $template;
        });

        $response = $this->actingAs($superadmin)->get('/superadmin/praxis-form-templates');

        $response->assertOk();
        $this->assertConfirmIsInsideAnAlpineScope($response->getContent(), "confirm('Dieses Formular wirklich löschen?");
    }

    public function test_addon_template_delete_confirmation_is_inside_an_alpine_scope(): void
    {
        $superadmin = $this->createSuperAdmin();
        $template = $this->onAdmin(function () {
            $template = AddonTemplate::create([
                'title' => 'E2E Vorlage',
                'badge_text' => 'TEST',
                'stat_text' => '99%',
                'headline' => 'E2E',
                'bullets' => ['Eins', 'Zwei'],
                'active' => true,
            ]);
            $this->createdTemplateIds[] = $template->id;

            return $template;
        });

        $response = $this->actingAs($superadmin)->get('/superadmin/addon-templates');

        $response->assertOk();
        $this->assertConfirmIsInsideAnAlpineScope($response->getContent(), "confirm('Diese Addon-Vorlage wirklich löschen?");
    }

    /**
     * Coupon-Export/Filter nutzten @change -- ohne x-data griff weder das
     * "Alle auswählen"-Kästchen noch der automatische Submit beim Filtern.
     */
    public function test_coupon_export_controls_are_inside_an_alpine_scope(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->get('/superadmin/coupons');

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('couponExport.toggleSelectAll', $html);
        $this->assertStringContainsString('x-data="{}"', $html);

        $xDataPos = strpos($html, 'x-data="{}"');
        $togglePos = strpos($html, 'couponExport.toggleSelectAll');
        $this->assertLessThan($togglePos, $xDataPos, 'x-data="{}" steht erst NACH dem Export-Kästchen -- die Direktive liegt außerhalb jedes Alpine-Scopes.');
    }
}
