<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Mindestversion ist PHP 8.5 (Entscheidung MINVER-1 der Inhaberin vom
 * 17.11.2026, gilt für Plattform, Plugins und Connectoren). composer.json,
 * composer.lock und die CI-Matrix (8.5 mit PostgreSQL 16 und 18, 8.6 nur
 * experimentell) müssen dazu passen.
 */
class PhpVersionPolicyTest extends TestCase
{
    public function test_composer_json_requires_php_85(): void
    {
        $composerJson = json_decode(file_get_contents(base_path('composer.json')), true);

        $this->assertSame('^8.5', $composerJson['require']['php'] ?? null);
    }

    public function test_composer_lock_is_consistent_with_the_php_85_requirement(): void
    {
        $composerLock = json_decode(file_get_contents(base_path('composer.lock')), true);

        $this->assertSame('^8.5', $composerLock['platform']['php'] ?? null);
    }

    /** PHP 8.5 mit der Mindestversion (16) und der aktuellen Version (18) von PostgreSQL; 8.6 darf scheitern. */
    public function test_the_ci_matrix_tests_the_minimum_php_version_and_86_only_experimentally(): void
    {
        $workflow = Yaml::parseFile(base_path('.github/workflows/tests.yml'));
        $job = $workflow['jobs']['tests'];
        $include = $job['strategy']['matrix']['include'] ?? [];

        $this->assertSame([8.5, 8.5, 8.6], array_column($include, 'php'));
        $this->assertSame([16, 18, 18], array_column($include, 'postgres'));
        $this->assertSame([false, false, true], array_column($include, 'experimental'));
        $this->assertSame('${{ matrix.experimental }}', $job['continue-on-error'] ?? null);
        $this->assertSame('postgres:${{ matrix.postgres }}', $job['services']['postgres']['image'] ?? null);
    }
}
