<?php

namespace Tests\Feature;

use App\Models\Favorite;
use App\Models\Module;
use App\Models\TenantBranding;
use Illuminate\Foundation\Exceptions\RegisterErrorViewPaths;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Mobile Bedienbarkeit (390 px) und leere Zustände: aufklappbare
 * Kursblöcke im Dashboard, Teilnehmer als Karten, Kurse im Mobilmenü als
 * Gruppe, Balkendiagramm, Hinweise in leeren Tabellen, Fehlerseiten im
 * Branding der Schule.
 */
class MobileUxAndEmptyStatesTest extends TestCase
{
    use InteractsWithTenants;

    public function test_dashboard_course_blocks_collapse_on_small_screens_except_the_first(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $learner, $this->existingCourse('SRC'));
        $this->grantEntitlement($tenant, $learner, $this->existingCourse('UBI'));

        $html = $this->actingAsInTenant($learner, $tenant)->get('/dashboard')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div id="course-block-0" (class="" )?:class="\{ \'max-sm:hidden\': ! open \}">/', $html);
        $this->assertStringContainsString('<div id="course-block-1" class="max-sm:hidden" :class="{ \'max-sm:hidden\': ! open }">', $html);
        $this->assertStringContainsString('aria-controls="course-block-1"', $html);
        // Kacheln mobil 2×2, ab sm in einer Reihe.
        $this->assertStringContainsString('grid grid-cols-2 sm:grid-cols-4', $html);
    }

    public function test_the_favorites_tile_shows_a_hint_while_it_is_empty(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->actingAsInTenant($learner, $tenant)->get('/dashboard')
            ->assertOk()->assertSee('Noch leer – markiere Fragen beim Lernen mit dem Stern.');

        [$question] = $this->anyPublishedQuestionIn(Module::whereIn('id', $course->modules()->pluck('module.id'))->first());
        $this->onAdmin(fn () => Favorite::create([
            'tenant_id' => $tenant->id, 'user_id' => $learner->id, 'question_id' => $question->id, 'context' => Favorite::CONTEXT_SMART_LEARNING,
        ]));

        $this->actingAsInTenant($learner, $tenant)->get('/dashboard')
            ->assertOk()->assertDontSee('Noch leer – markiere Fragen');
    }

    public function test_the_mobile_menu_groups_courses_so_logout_stays_close(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $html = $this->actingAsInTenant($learner, $tenant)->get('/dashboard')->assertOk()->getContent();
        $mobile = substr($html, strpos($html, 'id="mobile-menu"'));

        // Auf dem Dashboard ist die Gruppe zugeklappt, die Kurse stehen darin.
        $this->assertMatchesRegularExpression('/id="mobile-menu-courses" x-show="coursesOpen"\s+style="display: none"/', $mobile);
        $groupStart = strpos($mobile, 'id="mobile-menu-courses"');
        $this->assertGreaterThan($groupStart, strpos($mobile, route('progress.show', $course)));
        $this->assertStringContainsString('coursesOpen = ! coursesOpen', $mobile);
    }

    public function test_the_participants_table_turns_into_cards_below_sm(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $this->createTenantUser($tenant, 'learner', ['display_name' => 'Karten Teilnehmer']);

        $html = $this->actingAsInTenant($owner, $tenant)->get(route('admin.dashboard', ['tab' => 'participants']))->assertOk()->getContent();

        $this->assertStringContainsString('<table class="w-full text-sm block sm:table">', $html);
        $this->assertStringContainsString('<thead class="hidden sm:table-header-group', $html);
        $this->assertStringContainsString('<tr class="block sm:table-row', $html);
        $this->assertStringContainsString('Karten Teilnehmer', $html);
    }

    public function test_the_learning_phase_chart_is_a_bar_chart(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $learner, $this->existingCourse('SRC'));

        $html = $this->actingAsInTenant($owner, $tenant)->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Kursfortschritt in der Lernphase', $html);
        $this->assertStringNotContainsString('<polyline', $html);
        $this->assertStringContainsString('rounded-t-md', $html);
    }

    public function test_empty_coupon_and_shop_tables_explain_how_to_get_codes(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->get(route('admin.dashboard', ['tab' => 'coupons']));

        $response->assertOk();
        $response->assertSee('Importieren Sie gekaufte Codes über „Codes importieren“', false);
        $response->assertSee('Importieren Sie vorhandene Codes über „Codes importieren“', false);
        $response->assertSee('Verknüpfen Sie zuerst Ihr Shopkonto', false);
    }

    public function test_a_404_inside_a_school_uses_the_school_branding(): void
    {
        $tenant = $this->createTestTenant('Fehlerseiten Schule');
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/courses/00000000-0000-4000-8000-000000000000');

        $response->assertNotFound();
        $response->assertSee('Fehlerseiten Schule');
        $response->assertSee('--brand-primary: #123456', false);
        $response->assertSee('Seite nicht gefunden');
    }

    public function test_an_unknown_path_below_a_school_slug_is_branded_too(): void
    {
        $tenant = $this->createTestTenant('Pfad Schule');
        $this->onAdmin(function () use ($tenant) {
            TenantBranding::where('tenant_id', $tenant->id)->update(['primary_color' => '#654321']);
            // Pfad-Modus erkennt nur Slugs in Kleinbuchstaben.
            $tenant->update(['slug' => strtolower($tenant->slug)]);
        });

        $response = $this->get('http://'.config('app.central_domain').'/'.$tenant->slug.'/gibt-es-nicht-xyz');

        $response->assertNotFound();
        $response->assertSee('Pfad Schule');
        $response->assertSee('--brand-primary: #654321', false);
    }

    public function test_error_pages_without_a_school_show_the_platform_name_and_never_throw(): void
    {
        (new RegisterErrorViewPaths)();

        $html = view('errors.500')->render();

        $this->assertStringContainsString(config('app.name'), $html);
        $this->assertStringContainsString('--brand-primary: #005FD7', $html);
    }
}
