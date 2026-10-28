<?php

namespace Tests\Feature;

use App\Models\TenantBranding;
use App\Models\TenantUser;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review Oberfläche U6: Barrierefreiheit -- drei Kontrastwerte waren zu
 * schwach: Fließtext in text-slate-400 (2,6:1), die Teal-Buttons mit dem
 * bisherigen Standard-Farbwert #00A8A8 (2,9:1) und der WhatsApp-Button mit
 * dem hellen WhatsApp-Logo-Grün #25D366 (2,0:1). WCAG AA verlangt 4,5:1 für
 * normalen Text bzw. mindestens 3:1 für große/UI-Elemente.
 */
class ContrastRegressionTest extends TestCase
{
    use InteractsWithTenants;

    public function test_secondary_color_defaults_to_the_higher_contrast_teal(): void
    {
        $tenant = $this->createTestTenant();

        $this->assertNull($tenant->branding->secondary_color);
        $this->assertSame('#007A7A', $tenant->branding->secondaryColorOrDefault());
    }

    public function test_a_new_tenant_created_via_superadmin_gets_the_higher_contrast_teal(): void
    {
        $admin = $this->createSuperAdmin();

        $response = $this->actingAs($admin)->post('/superadmin/tenants', [
            'slug' => 'kontrast-test-'.strtolower(Str::random(8)),
            'name' => 'Kontrast Test Schule',
            'status' => 'active',
            'admin_first_name' => 'Anna',
            'admin_last_name' => 'Admin',
            'admin_email' => Str::uuid().'@example.test',
        ]);

        $response->assertSessionHasNoErrors();

        $branding = $this->onAdmin(fn () => TenantBranding::where('secondary_color', '#007A7A')->latest('updated_at')->first());
        $this->assertNotNull($branding);
        $this->createdTenantIds[] = $branding->tenant_id;

        $owner = $this->onAdmin(fn () => TenantUser::where('tenant_id', $branding->tenant_id)->where('role', 'owner')->first());
        $this->createdUserIds[] = $owner->user_id;
    }

    public function test_course_page_uses_the_higher_contrast_teal_as_the_brand_secondary_fallback(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertSee('var(--brand-secondary, #007A7A)', false);
        $response->assertDontSee('#00A8A8', false);
    }

    /**
     * Statischer Regressionstest fürs eigentliche Symptom: text-slate-400
     * (2,6:1 gegen Weiß) darf im Markup nur noch zusammen mit einem
     * hellen/helleren Gegenstück für den Light-Mode auftreten (also nie
     * "bloß" text-slate-400, sondern z. B. text-slate-600 dark:text-slate-400
     * oder text-slate-500 dark:text-slate-400) -- "dark:text-slate-400"
     * allein ist unkritisch, da slate-400 gegen die dunklen slate-800/900
     * Hintergründe im Dark Mode ausreichend Kontrast hat.
     */
    public function test_no_view_uses_bare_slate_400_text_without_a_darker_light_mode_counterpart(): void
    {
        $finder = new Finder;
        $finder->files()->in(resource_path('views'))->name('*.blade.php');

        $offenders = [];

        foreach ($finder as $file) {
            $content = $file->getContents();

            if (preg_match('/(?<!dark:)text-slate-400/', $content)) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'Bare text-slate-400 (ohne dunkleren Light-Mode-Gegenpart) gefunden in: '.implode(', ', $offenders));
    }
}
