<?php

namespace Tests\Feature;

use App\Models\AddonTemplate;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\Module;
use App\Models\PraxisFormSubmission;
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

    private array $createdModuleIds = [];

    private array $createdQuestionIds = [];

    private array $createdCourseIds = [];

    protected function tearDown(): void
    {
        // Erst Tenants (kaskadiert praxis_form_submission weg), dann die
        // Vorlage selbst -- sonst verletzt das Löschen der Vorlage die
        // Fremdschlüsselbindung einer noch existierenden Anmeldung.
        $this->cleanUpCreatedTenantsAndUsers();

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
            foreach ($this->createdModuleIds as $id) {
                Module::where('id', $id)->delete();
            }
            foreach ($this->createdQuestionIds as $id) {
                ContentQuestion::where('id', $id)->delete();
            }
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }
        });

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

    /**
     * Bug-Review (Lösch-Rückfragen von CSP blockiert): anders als die
     * oben getesteten Seiten nutzte diese Löschbestätigung bisher gar
     * keine Alpine-Direktive, sondern ein rohes onsubmit="return
     * confirm(...)" -- ein klassischer Inline-Event-Handler, den die CSP
     * (Bug-Review M9) ohne 'unsafe-inline' im script-src grundsätzlich
     * blockiert. Die Rückfrage erschien nie, die Anmeldung wurde beim
     * Klick sofort gelöscht.
     */
    public function test_praxis_form_submission_delete_confirmation_is_inside_an_alpine_scope(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');

        $course = $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-CSP-'.uniqid(),
                'name' => 'Test-Kurs für CSP-Rückfrage',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            return $course;
        });

        $this->grantEntitlement($tenant, $learner, $course);

        $template = $this->onAdmin(function () use ($course) {
            $template = PraxisFormTemplate::create([
                'title' => 'CSP-Test-Formular',
                'min_progress_percent' => 0,
                'course_ids' => [$course->id],
                'fields' => [],
                'active' => true,
            ]);
            $this->createdTemplateIds[] = $template->id;

            return $template;
        });

        $submission = $this->onAdmin(fn () => PraxisFormSubmission::create([
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $learner->id,
            'praxis_form_template_id' => $template->id,
            'data' => [],
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.praxis-form-submissions.edit', $submission));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString('onsubmit="return confirm', $html, 'Es steckt weiterhin ein von der CSP blockierter Inline-Handler im Markup.');
        $this->assertConfirmIsInsideAnAlpineScope($html, "confirm('Diese Anmeldung wirklich unwiderruflich löschen?");
    }

    /**
     * Bug-Review (Lösch-Rückfragen von CSP blockiert): dieselbe Lücke wie
     * oben, hier aber als onclick="return confirm(...)" auf dem Button
     * statt onsubmit= auf dem Formular -- die veröffentlichte Fassung
     * ersetzt dabei unwiderruflich die bisher veröffentlichte (siehe
     * publishRevision()), die Rückfrage entfiel aber komplett.
     */
    public function test_question_edit_page_publish_confirmation_is_inside_an_alpine_scope(): void
    {
        $superadmin = $this->createSuperAdmin();
        [$question] = $this->makeQuestionWithPendingReviewForAlpineTest();

        $response = $this->actingAs($superadmin)->get(route('superadmin.questions.edit', $question));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString('onclick="return confirm', $html, 'Es steckt weiterhin ein von der CSP blockierter Inline-Handler im Markup.');
        $this->assertConfirmIsInsideAnAlpineScope($html, "confirm('Diese Fassung jetzt veröffentlichen?");
    }

    /** Dieselbe Veröffentlichen-Rückfrage, hier in der Fragenliste statt der Bearbeitungsseite. */
    public function test_question_index_page_publish_confirmation_is_inside_an_alpine_scope(): void
    {
        $superadmin = $this->createSuperAdmin();
        [, $module] = $this->makeQuestionWithPendingReviewForAlpineTest();

        $response = $this->actingAs($superadmin)->get(route('superadmin.questions.index', $module));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString('onclick="return confirm', $html, 'Es steckt weiterhin ein von der CSP blockierter Inline-Handler im Markup.');
        $this->assertConfirmIsInsideAnAlpineScope($html, "confirm('Die Review-Fassung dieser Frage jetzt veröffentlichen?");
    }

    /** @return array{0: ContentQuestion, 1: Module} */
    private function makeQuestionWithPendingReviewForAlpineTest(): array
    {
        return $this->onAdmin(function () {
            $module = Module::create(['code' => 'TEST-CSP-MOD-'.uniqid(), 'name' => 'Test Modul CSP', 'version' => '1', 'status' => 'published']);
            $this->createdModuleIds[] = $module->id;

            $question = ContentQuestion::create(['content_id' => 'TEST-CSP-Q-'.uniqid(), 'language' => 'de-DE', 'active' => true]);
            $this->createdQuestionIds[] = $question->id;
            $module->questions()->attach($question->id, ['required' => true]);

            ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => 1,
                'question_type' => 'single_choice',
                'question_text' => 'Alte Fassung',
                'editorial_status' => 'published',
                'rights_status' => 'licensed',
            ]);

            ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => 2,
                'question_type' => 'single_choice',
                'question_text' => 'Neue Fassung aus Reimport',
                'editorial_status' => 'review',
                'rights_status' => 'review',
            ]);

            return [$question, $module];
        });
    }
}
