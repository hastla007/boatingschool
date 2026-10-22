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
}
