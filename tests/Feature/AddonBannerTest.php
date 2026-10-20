<?php

namespace Tests\Feature;

use App\Models\AddonTemplate;
use App\Models\Progress;
use App\Models\TenantAddon;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Der Upsell-Banner (siehe admin/tabs/addons.blade.php) erscheint auf der
 * Kursseite nur, wenn die Bootsschule ein Addon für genau diesen Kurs
 * aktiviert hat UND der Kursfortschritt des Lerners die konfigurierte
 * Schwelle erreicht hat; ein Dismiss passiert client-seitig via
 * localStorage, ist also hier nicht testbar.
 */
class AddonBannerTest extends TestCase
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
                'bullets' => ['Vorteil Eins', 'Vorteil Zwei'],
                'cta_label' => 'Jetzt sichern',
                'active' => true,
            ], $overrides));
            $this->createdTemplateIds[] = $template->id;

            return $template;
        });
    }

    private function masterAllQuestions($tenant, $learner, $course): void
    {
        $this->onAdmin(function () use ($tenant, $learner, $course) {
            $questionIds = $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id')->unique();

            foreach ($questionIds as $questionId) {
                Progress::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'user_id' => $learner->id, 'question_id' => $questionId],
                    ['learning_state' => 'gefestigt', 'mastery_score' => 1, 'attempt_count' => 1, 'correct_count' => 1]
                );
            }
        });
    }

    public function test_banner_is_shown_once_the_progress_threshold_is_reached(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);
        $template = $this->createTestTemplate();

        $this->onAdmin(fn () => TenantAddon::create([
            'tenant_id' => $tenant->id,
            'addon_template_id' => $template->id,
            'active' => true,
            'coupon_code' => 'SOMMER25',
            'price_label' => '149 €',
            'trigger_course_id' => $course->id,
            'trigger_percent' => 50,
            'offer_url' => 'https://shop.example.test/grosses-paket',
        ]));

        $this->masterAllQuestions($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertSee('Sichere dir jetzt das große Paket');
        $response->assertSee('BESTSELLER');
        $response->assertSee('149 €', false);
        $response->assertSee('SOMMER25');
        $response->assertSee('https://shop.example.test/grosses-paket');
        $response->assertSee('Vorteil Eins');
    }

    public function test_banner_is_hidden_below_the_progress_threshold(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);
        $template = $this->createTestTemplate();

        $this->onAdmin(fn () => TenantAddon::create([
            'tenant_id' => $tenant->id,
            'addon_template_id' => $template->id,
            'active' => true,
            'trigger_course_id' => $course->id,
            'trigger_percent' => 50,
            'offer_url' => 'https://shop.example.test/grosses-paket',
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertDontSee('Sichere dir jetzt das große Paket');
    }

    public function test_banner_is_hidden_when_the_addon_is_not_activated_for_the_tenant(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);
        $this->createTestTemplate();

        $this->masterAllQuestions($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertDontSee('Sichere dir jetzt das große Paket');
    }

    public function test_banner_is_hidden_on_a_different_course_than_configured(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $otherCourse = $this->existingCourse('UBI');
        $this->grantEntitlement($tenant, $learner, $course);
        $this->grantEntitlement($tenant, $learner, $otherCourse);
        $template = $this->createTestTemplate();

        $this->onAdmin(fn () => TenantAddon::create([
            'tenant_id' => $tenant->id,
            'addon_template_id' => $template->id,
            'active' => true,
            'trigger_course_id' => $otherCourse->id,
            'trigger_percent' => 0,
            'offer_url' => 'https://shop.example.test/grosses-paket',
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertDontSee('Sichere dir jetzt das große Paket');
    }
}
