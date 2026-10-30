<?php

namespace Tests\Feature;

use App\Models\AddonTemplate;
use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\CoursePromoBanner;
use App\Models\Module;
use App\Models\Progress;
use App\Models\TenantAddon;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * "Deine Angebote von <Bootsschule>" sammelt alle Addon- und
 * Kursempfehlungs-Banner, für die ein Lerner gerade in Frage kommt --
 * dieselbe Freischalt-Logik wie auf den Kursseiten (siehe
 * CourseController::show()), aber unabhängig vom per-X-Dismiss auf der
 * jeweiligen Kursseite (das steckt nur in localStorage dort).
 */
class OffersPageTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdTemplateIds = [];

    private array $createdCourseIds = [];

    private array $createdModuleIds = [];

    private array $createdQuestionIds = [];

    protected function tearDown(): void
    {
        // Entitlements verweisen per Fremdschlüssel auf den Kurs -- erst
        // die Mandanten (samt ihrer Entitlements) aufräumen, danach den
        // isolierten Testkurs, sonst schlägt dessen Löschung fehl.
        $this->cleanUpCreatedTenantsAndUsers();

        $this->onAdmin(function () {
            foreach ($this->createdTemplateIds as $id) {
                AddonTemplate::where('id', $id)->delete();
            }
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }
            foreach ($this->createdModuleIds as $id) {
                Module::where('id', $id)->delete();
            }
            foreach ($this->createdQuestionIds as $id) {
                ContentQuestion::where('id', $id)->delete();
            }
        });

        parent::tearDown();
    }

    /**
     * Wie CourseProgressServiceTest::makeCourseWithActiveQuestions() -- ein
     * isolierter Testkurs mit frei wählbarer Fragenzahl, um einen exakten
     * (nicht rundungsfreundlichen) Fortschrittsprozentsatz zu erzeugen.
     *
     * @return array{0: CourseDefinition, 1: array<int, string>}
     */
    private function makeCourseWithActiveQuestions(int $count): array
    {
        return $this->onAdmin(function () use ($count) {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-OFFERS-'.uniqid(),
                'name' => 'Test-Kurs für Angebote-Rundung',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $module = Module::create([
                'code' => 'TEST-OFFERS-MODULE-'.uniqid(),
                'name' => 'Testmodul',
                'version' => '1',
                'status' => 'published',
            ]);
            $this->createdModuleIds[] = $module->id;
            $course->modules()->attach($module->id, ['sort_order' => 1, 'required' => true]);

            $questionIds = [];
            for ($i = 0; $i < $count; $i++) {
                $question = ContentQuestion::create(['content_id' => 'TEST-OFFERS-Q-'.uniqid(), 'language' => 'de-DE', 'active' => true]);
                $this->createdQuestionIds[] = $question->id;
                $questionIds[] = $question->id;
                $module->questions()->attach($question->id, ['sort_order' => $i + 1, 'required' => true]);
            }

            return [$course, $questionIds];
        });
    }

    private function createTestTemplate(): AddonTemplate
    {
        return $this->onAdmin(function () {
            $template = AddonTemplate::create([
                'title' => 'E2E Großes Paket',
                'headline' => 'Sichere dir jetzt das große Paket',
                'bullets' => ['Vorteil 1', 'Vorteil 2'],
                'cta_label' => 'Jetzt sichern',
                'active' => true,
            ]);
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

    public function test_offers_page_shows_the_tenant_name_in_the_heading(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/angebote');

        $response->assertOk();
        $response->assertSee('Deine Angebote von '.$tenant->name);
    }

    public function test_offers_page_shows_an_eligible_addon(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);
        $this->masterAllQuestions($tenant, $learner, $course);
        $template = $this->createTestTemplate();

        $this->onAdmin(fn () => TenantAddon::create([
            'tenant_id' => $tenant->id,
            'addon_template_id' => $template->id,
            'active' => true,
            'price_label' => '299 €',
            'discount_percent' => 20,
            'coupon_code' => 'PAKET20',
            'trigger_course_id' => $course->id,
            'trigger_percent' => 10,
            'offer_url' => 'https://shop.example.test/grosses-paket',
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get('/angebote');

        $response->assertOk();
        $response->assertSee('Sichere dir jetzt das große Paket');
        $response->assertSee('299 €', false);
        $response->assertSee('239,20 €', false);
        $response->assertSee('PAKET20');
        $response->assertSee('https://shop.example.test/grosses-paket');
    }

    public function test_offers_page_hides_an_addon_below_its_threshold(): void
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

        $response = $this->actingAsInTenant($learner, $tenant)->get('/angebote');

        $response->assertOk();
        $response->assertDontSee('Sichere dir jetzt das große Paket');
        $response->assertSee('Aktuell sind keine Empfehlungen für dich verfügbar.');
    }

    public function test_offers_page_shows_an_eligible_promo_banner_even_after_it_was_dismissed_on_the_course_page(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(fn () => CoursePromoBanner::create([
            'tenant_id' => $tenant->id,
            'active' => true,
            'headline' => 'Jetzt auch den Seefunk-Schein sichern!',
            'description' => 'Perfekt in Kombination.',
            'bullets' => ['Nur 12 Prüfungsbögen'],
            'price_label' => '189 €',
            'discount_percent' => 15,
            'coupon_code' => 'SRC15',
            'offer_url' => 'https://shop.example.test/src',
            'course_ids' => [$course->id],
            'trigger_percent' => 0,
        ]));

        // Dismiss ist rein client-seitig (localStorage) und betrifft nur die
        // Kursseite -- serverseitig gibt es hier nichts zu simulieren, die
        // Angebotsseite zeigt den Banner unabhängig davon immer an.
        $response = $this->actingAsInTenant($learner, $tenant)->get('/angebote');

        $response->assertOk();
        $response->assertSee('Jetzt auch den Seefunk-Schein sichern!');
        $response->assertSee('Nur 12 Prüfungsbögen');
        $response->assertSee('189 €', false);
        $response->assertSee('160,65 €', false);
        $response->assertSee('SRC15');
    }

    public function test_offers_page_hides_a_promo_banner_below_its_threshold(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(fn () => CoursePromoBanner::create([
            'tenant_id' => $tenant->id,
            'active' => true,
            'headline' => 'Erst ab 50%',
            'offer_url' => 'https://shop.example.test/x',
            'course_ids' => [$course->id],
            'trigger_percent' => 50,
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get('/angebote');

        $response->assertOk();
        $response->assertDontSee('Erst ab 50%');
        $response->assertSee('Aktuell sind keine Kursangebote für dich verfügbar.');
    }

    /**
     * Bug-Review BB3: CourseController::show() vergleicht Addon-Trigger
     * gegen den UNGERUNDETEN Fortschritt (siehe Bug-Review "Gates
     * vergleichen den gerundeten Wert") -- die Angebote-Seite verwendete
     * für denselben Vergleich bisher den GERUNDETEN overallPercent(). 3
     * von 8 Fragen gefestigt sind exakt 37,5%; round(37.5) rundet in PHP
     * auf 38 auf. Ein Trigger von 38% darf das Addon deshalb noch nicht
     * freischalten, exakt wie auf der Kursseite.
     */
    public function test_offers_page_addon_is_not_shown_at_a_percentage_that_would_round_up_to_the_trigger(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $questionIds] = $this->makeCourseWithActiveQuestions(8);
        $this->grantEntitlement($tenant, $learner, $course);
        $template = $this->createTestTemplate();

        $this->onAdmin(function () use ($tenant, $learner, $course, $questionIds, $template) {
            foreach (array_slice($questionIds, 0, 3) as $questionId) {
                Progress::create([
                    'tenant_id' => $tenant->id, 'user_id' => $learner->id, 'question_id' => $questionId,
                    'learning_state' => 'gefestigt', 'mastery_score' => 1, 'attempt_count' => 1, 'correct_count' => 1,
                ]);
            }

            TenantAddon::create([
                'tenant_id' => $tenant->id,
                'addon_template_id' => $template->id,
                'active' => true,
                'trigger_course_id' => $course->id,
                'trigger_percent' => 38,
                'offer_url' => 'https://shop.example.test/grosses-paket',
            ]);
        });

        $response = $this->actingAsInTenant($learner, $tenant)->get('/angebote');

        $response->assertOk();
        $response->assertDontSee('Sichere dir jetzt das große Paket');
        $response->assertSee('Aktuell sind keine Empfehlungen für dich verfügbar.');
    }

    public function test_offers_nav_link_is_shown_in_the_main_navigation(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Angebote');
        $response->assertSee(route('offers.index'), false);
    }
}
