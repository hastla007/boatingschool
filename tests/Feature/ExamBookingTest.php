<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\CourseExamRegistration;
use App\Models\Module;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ExamBooking;
use App\Support\ExamRegistration;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Seite "Prüfung buchen" (Selbstanmeldung): jeder Kurs hat eine Seite mit
 * Anmeldeweg, Gebühren und Prüfungsteilen, die Variante des Kurses zuerst,
 * und die Theorie-Eckdaten passen zu den amtlichen Regelwerken.
 */
class ExamBookingTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_radio_courses_explain_registration_fees_and_exam_parts(): void
    {
        [$tenant, $learner, [$combo, $addon]] = $this->selfRegisteringLearner(['SRC-UBI', 'ADD-UBI-TO-SRC']);

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$combo->id}")->assertOk()->assertSee('Prüfung buchen');
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$combo->id}/pruefung-buchen")
            ->assertOk()->assertSee('SRC-Prüfung buchen')->assertSee('ca. 127,88 €')->assertSee('24 Fragen aus 180')
            ->assertSee('https://www.dmyv.de/fuehrerschein/-funk/pruefungen/pruefungsausschuesse/')->assertSee('Bundesnetzagentur')
            ->assertSee('Zur Praxis-Prüfungssimulation');
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$combo->id}/pruefung-buchen?teil=UBI")
            ->assertOk()->assertSee('UBI-Prüfung buchen')->assertSee('ca. 108,71 €')
            ->assertSeeInOrder(['Vollprüfung', '22 Fragen aus 130', 'Weitere Prüfungsformen', 'Ergänzungsprüfung für SRC-Inhaber', '10 Fragen aus 79']);
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$addon->id}/pruefung-buchen")
            ->assertOk()->assertSee('UBI-Prüfung buchen')->assertSee('Du hast schon das SRC')
            ->assertSeeInOrder(['Ergänzungsprüfung für SRC-Inhaber', 'Weitere Prüfungsformen', 'Vollprüfung']);
    }

    public function test_boat_licence_and_flare_courses_have_their_own_pages(): void
    {
        [$tenant, $learner, [$seeBin, $seeAddon, $fkn, $skn]] = $this->selfRegisteringLearner(['SBF-SEE-BIN', 'ADD-BIN-TO-SEE', 'FKN', 'SKN']);

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$seeBin->id}/pruefung-buchen")
            ->assertOk()->assertSee('SBF-See-Prüfung buchen')->assertSee('ca. 148,38 €')->assertSee('Ärztliches Zeugnis')
            ->assertSee('30 Fragen: 7 Basisfragen aus 72, 23 See-Fragen aus 213')->assertSee('Basisfragen mind. 5 von 7 und See-Fragen mind. 18 von 23')
            ->assertSee('Navigationsaufgabe')->assertSee('SBF Binnen');
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$seeBin->id}/pruefung-buchen?teil=SBF-BIN")
            ->assertOk()->assertSee('SBF-Binnen-Prüfung buchen')->assertSee('Vollprüfung unter Antriebsmaschine')->assertSee('Ergänzung Segeln für SBF-Binnen-Inhaber');
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$seeAddon->id}/pruefung-buchen")
            ->assertOk()->assertSee('Du hast schon den SBF Binnen')->assertSeeInOrder(['Ergänzungsprüfung für SBF-Binnen-Inhaber', 'Vollprüfung']);
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$fkn->id}/pruefung-buchen")
            ->assertOk()->assertSee('FKN-Prüfung buchen')->assertSee('mind. 24 von 30 Punkten')->assertSee('15 Fragen aus 62');
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$skn->id}/pruefung-buchen")
            ->assertOk()->assertSee('SKN-Prüfung buchen')->assertSee('18 Jahre')->assertDontSee('Bestanden:');
    }

    public function test_every_catalogue_course_has_a_page_and_every_variant_is_used(): void
    {
        $codes = $this->onAdmin(fn () => CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->pluck('code')->all());
        $used = [];

        foreach ($codes as $code) {
            $pages = ExamBooking::pagesFor(new CourseDefinition(['code' => $code]));
            $this->assertNotEmpty($pages, "Keine Seite \"Prüfung buchen\" für {$code}");
            foreach ($pages as $page => $variant) {
                $this->assertArrayHasKey($variant, ExamBooking::page($page)['variants'], "{$code}: Variante {$variant} fehlt auf {$page}");
                $used[$page][] = $variant;
            }
        }

        foreach (ExamBooking::pages() as $key => $page) {
            $this->assertEqualsCanonicalizing(array_keys($page['variants']), array_unique($used[$key] ?? []), "Ungenutzte Variante auf {$key}");
        }
    }

    public function test_theory_facts_match_the_official_rule_sets_and_catalogues(): void
    {
        $this->onAdmin(function () {
            foreach (ExamBooking::pages() as $pageKey => $page) {
                foreach ($page['variants'] as $variantKey => $variant) {
                    $where = "{$pageKey}/{$variantKey}";
                    foreach ($variant['parts'] as $part) {
                        $this->assertTrue(Module::where('code', $part['module'])->exists(), "{$where}: Modul {$part['module']}");
                    }
                    if ($variant['course'] === null) {
                        $this->assertTrue(collect($variant['parts'])->every(fn ($p) => ! isset($p['min'])), "{$where}: Bestehensgrenze ohne amtliches Regelwerk");

                        continue;
                    }

                    $ruleSet = $this->existingCourse($variant['course'])->activeExamRuleSet();
                    $this->assertNotNull($ruleSet, "{$where}: kein verifiziertes Regelwerk");
                    $this->assertSame($ruleSet->time_limit_seconds, $variant['minutes'] * 60, "{$where}: Dauer");
                    $this->assertEqualsWithDelta($ruleSet->passing_rule['min_correct_ratio'], array_sum(array_column($variant['parts'], 'min')) / array_sum(array_column($variant['parts'], 'of')), 1e-9, "{$where}: Bestehensgrenze");
                    foreach ($ruleSet->passing_rule['parts'] ?? [] as $rulePart) {
                        $part = collect($variant['parts'])->firstWhere('module', $rulePart['module']);
                        $this->assertSame([$rulePart['of'], $rulePart['min_correct']], [$part['of'], $part['min']], "{$where}: Teil {$rulePart['module']}");
                    }
                }
            }
        });
    }

    public function test_the_page_needs_self_registration_and_access(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $sbf = $this->existingCourse('SBF-SEE');

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$sbf->id}/pruefung-buchen")->assertForbidden();
        $this->grantEntitlement($tenant, $learner, $sbf);
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$sbf->id}/pruefung-buchen")->assertNotFound();
    }

    /** @return array{0: Tenant, 1: User, 2: list<CourseDefinition>} */
    private function selfRegisteringLearner(array $codes): array
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $courses = array_map(fn ($code) => $this->existingCourse($code), $codes);
        foreach ($courses as $course) {
            $this->grantEntitlement($tenant, $learner, $course);
        }
        $this->onAdmin(function () use ($tenant, $courses) {
            foreach ($courses as $course) {
                CourseExamRegistration::create(['tenant_id' => $tenant->id, 'course_id' => $course->id, 'mode' => ExamRegistration::SELF]);
            }
        });

        return [$tenant, $learner, $courses];
    }
}
