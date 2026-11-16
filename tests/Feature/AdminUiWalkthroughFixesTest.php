<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\PlatformSetting;
use Illuminate\Auth\Notifications\ResetPassword;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Kleinere Befunde aus dem Browser-Rundgang: Reiterleiste, „Kurs
 * freischalten“ ohne Codes, Team-Tabelle auf dem Handy, Knopfstil,
 * Beschriftung der Code-Kästchen, Plattformname in Mails, Kompassrose.
 */
class AdminUiWalkthroughFixesTest extends TestCase
{
    use InteractsWithTenants;

    private array $couponIds = [];

    protected function tearDown(): void
    {
        $this->onAdmin(fn () => Coupon::whereIn('id', $this->couponIds)->delete());
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    public function test_admin_tabs_wrap_on_desktop_and_keep_the_aria_tab_pattern(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $html = $this->actingAsInTenant($owner, $tenant)->get('/admin')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<div class="[^"]*\bmd:flex-wrap\b[^"]*\bmd:overflow-x-visible\b[^"]*" role="tablist"/', $html);
        $this->assertStringContainsString('@keydown.arrow-right.prevent="moveTab($event, 1)"', $html);
        $this->assertStringContainsString('role="tab" id="admin-tab-participants" aria-controls="admin-panel-participants"', $html);
    }

    public function test_granting_a_course_without_free_codes_shows_a_hint_and_a_disabled_button(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $this->createTenantUser($tenant, 'learner', ['display_name' => 'Ohne Codes']);

        $html = $this->actingAsInTenant($owner, $tenant)->get('/admin?tab=participants')->assertOk()->getContent();
        $this->assertStringContainsString('Keine freien Zugangscodes – bitte zuerst Codes bestellen oder erzeugen.', $html);
        $this->assertMatchesRegularExpression('/<button type="submit" disabled[^>]*>Freischalten<\/button>/', $html);

        // Mit einem freien Code: kein Hinweis, Knopf aktiv.
        $this->onAdmin(function () use ($tenant) {
            $coupon = Coupon::create(['code' => Coupon::generateUniqueCode(), 'course_id' => $this->existingCourse('SRC')->id, 'tenant_id' => $tenant->id, 'batch_label' => 'Test']);
            $this->couponIds[] = $coupon->id;
        });
        $html = $this->actingAsInTenant($owner, $tenant)->get('/admin?tab=participants')->assertOk()->getContent();
        $this->assertStringNotContainsString('Keine freien Zugangscodes – bitte zuerst', $html);
        $this->assertMatchesRegularExpression('/<button type="submit"  class="[^"]*"[^>]*>Freischalten<\/button>/', $html);
    }

    public function test_team_rows_stack_on_phones(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $this->createTenantUser($tenant, 'instructor');

        $this->actingAsInTenant($owner, $tenant)->get('/admin?tab=participants')->assertOk()
            ->assertSee('<table class="w-full text-sm mb-4 block sm:table">', false)
            ->assertSee('<tr class="block sm:table-row border-t border-slate-100 dark:border-slate-700 py-2 sm:py-0">', false);
    }

    public function test_profile_buttons_use_the_normal_button_style(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $html = $this->actingAsInTenant($learner, $tenant)->get('/profile')->assertOk()->getContent();
        $this->assertStringNotContainsString('uppercase tracking-widest', $html);
        $this->assertStringContainsString('font-semibold text-sm text-white', $html);
    }

    public function test_superadmin_coupon_checkboxes_are_labelled(): void
    {
        $superadmin = $this->createSuperAdmin();
        $code = $this->onAdmin(function () {
            $coupon = Coupon::create(['code' => Coupon::generateUniqueCode(), 'course_id' => $this->existingCourse('SRC')->id, 'batch_label' => 'Test']);
            $this->couponIds[] = $coupon->id;

            return $coupon->code;
        });

        $this->actingAs($superadmin)->get('/superadmin/coupons?search='.$code)->assertOk()
            ->assertSee('aria-label="Code '.$code.' auswählen"', false);
    }

    public function test_mail_header_and_footer_use_the_platform_name_instead_of_app_name(): void
    {
        config(['app.name' => 'Roher APP_NAME']);
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $html = (string) (new ResetPassword('token'))->toMail($learner)->render();
        $siteName = $this->onAdmin(fn () => PlatformSetting::siteName());
        $this->assertStringNotContainsString('Roher APP_NAME', $html);
        $this->assertStringContainsString('© '.date('Y').' '.e($siteName), $html);
        $this->assertStringContainsString('<title>'.e($siteName).'</title>', $html);
    }

    public function test_the_compass_motif_is_anchored_right_so_it_is_not_clipped(): void
    {
        $view = file_get_contents(resource_path('views/courses/show.blade.php'));
        $this->assertStringNotContainsString('preserveAspectRatio="xMidYMid slice"', $view);
        $this->assertSame(2, substr_count($view, 'viewBox="0 0 800 200" preserveAspectRatio="xMaxYMid slice"'));
        $this->assertStringContainsString('<text x="69" y="4"', $view);
    }
}
