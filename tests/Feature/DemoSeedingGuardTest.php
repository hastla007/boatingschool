<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * O3: UserAndEntitlementSeeder (und alle davon abhängigen Demo-Seeder)
 * legten die beiden fiktiven Bootsschulen "mueller"/"hanse-kiel" samt
 * Admin-/Lerner-Konten bislang in JEDER Umgebung mit dem Standardpasswort
 * "password" an -- auch in einer echten Produktivumgebung, in der
 * `php artisan db:seed` liefe. Ein voller Lauf von DatabaseSeeder ist in
 * einem Test zu teuer (Content-Import, dutzende Unterseeder); stattdessen
 * wird hier -- analog zu
 * EnvironmentHardeningTest::test_dev_tenant_override_config_default_literal_is_false
 * -- direkt am Quelltext geprüft, dass die Demo-Konten-Seeder tatsächlich
 * hinter der environment('local', 'testing')-Weiche liegen und
 * PraxisFormTemplateSeeder (echter, globaler Startinhalt ohne
 * Mandantenbezug) bewusst davon ausgenommen bleibt.
 */
class DemoSeedingGuardTest extends TestCase
{
    public function test_demo_tenant_seeders_are_gated_behind_local_or_testing_environment(): void
    {
        $source = file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));

        $this->assertMatchesRegularExpression(
            "/environment\\('local',\\s*'testing'\\)/",
            $source,
            'DatabaseSeeder muss die Demo-Mandanten-Seeder hinter app()->environment(\'local\', \'testing\') ausblenden.'
        );

        preg_match("/environment\\('local',\\s*'testing'\\)\\)\\s*\\{(.*?)\\n\\s*\\}/s", $source, $matches);
        $this->assertNotEmpty($matches, 'Konnte den environment()-Gate-Block in DatabaseSeeder.php nicht finden.');
        $gatedBlock = $matches[1];

        foreach ([
            'TenantSeeder',
            'UserAndEntitlementSeeder',
            'AddonDemoSeeder',
            'CoursePromoBannerDemoSeeder',
            'TenantCourseLocationDemoSeeder',
            'DemoLearningActivitySeeder',
            'WillkommensboxDemoSeeder',
            'PraxisAnmeldungDemoSeeder',
        ] as $demoSeeder) {
            $this->assertStringContainsString(
                $demoSeeder,
                $gatedBlock,
                "{$demoSeeder} legt Konten/Daten für die fiktiven Demo-Mandanten an und muss deshalb innerhalb des environment('local', 'testing')-Blocks aufgerufen werden."
            );
        }

        // PraxisFormTemplateSeeder pflegt globale, mandantenunabhängige
        // Startinhalte (kein tenant_id, siehe Migration) -- kein Demo-Konto,
        // muss also unverändert in jeder Umgebung laufen.
        $this->assertStringNotContainsString(
            'PraxisFormTemplateSeeder',
            $gatedBlock,
            'PraxisFormTemplateSeeder ist globaler Startinhalt ohne Mandantenbezug und darf nicht hinter die Demo-Weiche wandern.'
        );
        $this->assertStringContainsString('PraxisFormTemplateSeeder::class', $source);
    }

    public function test_the_content_review_attribution_account_no_longer_uses_the_literal_default_password(): void
    {
        $source = file_get_contents(base_path('database/seeders/ExamRuleSetSeeder.php'));

        $this->assertDoesNotMatchRegularExpression(
            "/(bcrypt|Hash::make)\\('password'\\)/",
            $source,
            "ExamRuleSetSeeder darf das content-admin-Konto (nur für die reviewed_by-Zuordnung, kein echter Login) nicht mit dem erratbaren Standardpasswort 'password' anlegen."
        );
        $this->assertStringContainsString('Str::random', $source);
    }
}
