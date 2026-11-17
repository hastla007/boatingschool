<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Mindestversion ist PHP 8.4 (Entscheidung des Inhabers vom 16.11.2026,
 * Voraussetzung für PHPUnit 13). composer.json, composer.lock und die
 * CI-Matrix (Mindestversion 8.4, aktuell 8.5) müssen dazu passen.
 */
class PhpVersionPolicyTest extends TestCase
{
    public function test_composer_json_requires_php_84(): void
    {
        $composerJson = json_decode(file_get_contents(base_path('composer.json')), true);

        $this->assertSame('^8.4', $composerJson['require']['php'] ?? null);
    }

    public function test_composer_lock_is_consistent_with_the_php_84_requirement(): void
    {
        $composerLock = json_decode(file_get_contents(base_path('composer.lock')), true);

        $this->assertSame('^8.4', $composerLock['platform']['php'] ?? null);
    }

    /** Die CI prüft die Mindestversion aus composer.json (8.4) und die aktuelle PHP-Version (8.5). */
    public function test_the_ci_matrix_tests_the_minimum_and_the_latest_php_version(): void
    {
        $workflow = Yaml::parseFile(base_path('.github/workflows/tests.yml'));
        $include = $workflow['jobs']['tests']['strategy']['matrix']['include'] ?? [];

        $this->assertSame([8.4, 8.5], array_column($include, 'php'));
        $this->assertSame([16, 18], array_column($include, 'postgres'));
        $this->assertSame('postgres:${{ matrix.postgres }}', $workflow['jobs']['tests']['services']['postgres']['image'] ?? null);
    }
}
