<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * CI startete bislang nur auf Pushes nach "master" (Default-Branch-Name
 * im Laravel-Grundgerüst) -- der tatsächliche Entwicklungs-/Default-Branch
 * dieses Repos heißt aber "main", wodurch der Workflow bei einem direkten
 * Push auf main nie ausgelöst wurde (nur noch über pull_request/Cron).
 */
class CiWorkflowConfigurationTest extends TestCase
{
    public function test_the_tests_workflow_triggers_on_push_to_main_not_master(): void
    {
        $workflow = Yaml::parseFile(base_path('.github/workflows/tests.yml'));

        $pushBranches = $workflow['on']['push']['branches'] ?? [];

        $this->assertContains('main', $pushBranches, '.github/workflows/tests.yml muss bei einem Push auf "main" (Default-Branch) laufen.');
        $this->assertNotContains('master', $pushBranches, '.github/workflows/tests.yml darf nicht mehr auf den im Repo nicht existierenden Branch "master" triggern.');
    }

    /** Connector-Aufgabe BS-05: E2E-Lauf in echtem WordPress, siehe docs/commerce/wordpress-integrationstest.md. */
    public function test_the_connector_wordpress_workflow_runs_the_e2e_script_on_relevant_changes(): void
    {
        $workflow = Yaml::parseFile(base_path('.github/workflows/connector-wordpress.yml'));

        $this->assertArrayHasKey('workflow_dispatch', $workflow['on']);
        foreach (['push', 'pull_request'] as $event) {
            $paths = $workflow['on'][$event]['paths'] ?? [];
            foreach (['integrations/woocommerce-connector/**', 'app/Commerce/**', 'app/Http/Controllers/Commerce/**', 'app/Http/Middleware/Commerce/**', 'routes/**', 'tests/connector-e2e/**', '.github/workflows/connector-wordpress.yml'] as $path) {
                $this->assertContains($path, $paths, "{$event} muss bei Änderungen an {$path} auslösen.");
            }
            // Ohne Branch-Filter: auch ein Push auf einen Feature-Branch startet den Lauf.
            $this->assertArrayNotHasKey('branches', $workflow['on'][$event]);
        }

        $job = $workflow['jobs']['wordpress-e2e'];
        $this->assertSame(['postgres', 'mysql'], array_keys($job['services']));
        $runs = implode("\n", array_column($job['steps'], 'run'));
        foreach (['tests/connector-e2e/install-wordpress.sh', 'tests/connector-e2e/run.sh', 'tests/connector-e2e/router.php'] as $script) {
            $this->assertStringContainsString($script, $runs);
            $this->assertFileExists(base_path($script));
        }
        $this->assertTrue(is_executable(base_path('tests/connector-e2e/run.sh')) && is_executable(base_path('tests/connector-e2e/install-wordpress.sh')), 'Die E2E-Skripte müssen ausführbar sein (CI ruft sie direkt auf).');
        $this->assertSame('8.4', (string) $job['steps'][1]['with']['php-version']);
    }
}
