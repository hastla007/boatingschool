<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * composer.json verlangte bislang "php": "^8.4", obwohl die Plattform
 * tatsächlich auf PHP 8.3 betrieben wird (und keine der Abhängigkeiten
 * echte 8.4-Funktionen braucht, laravel/framework ^11.31 selbst
 * unterstützt bereits PHP 8.2+) -- composer install/update verlangte
 * dadurch ohne --ignore-platform-reqs eine PHP-Version, die in keiner
 * tatsächlich genutzten Umgebung installiert ist. composer.lock pinnte
 * zusätzlich einige Symfony-Komponenten (clock, css-selector,
 * event-dispatcher, string, translation, yaml) in einer 8.x-Linie, die
 * selbst PHP >=8.4.1 voraussetzt -- nur möglich, weil die vorherige
 * ^8.4-Anforderung diese Auswahl überhaupt erst zugelassen hatte.
 */
class PhpVersionPolicyTest extends TestCase
{
    public function test_composer_json_requires_php_83_not_84(): void
    {
        $composerJson = json_decode(file_get_contents(base_path('composer.json')), true);

        $this->assertSame('^8.3', $composerJson['require']['php'] ?? null);
    }

    public function test_composer_lock_is_consistent_with_the_php_83_requirement(): void
    {
        $composerLock = json_decode(file_get_contents(base_path('composer.lock')), true);

        $this->assertSame('^8.3', $composerLock['platform']['php'] ?? null);
    }

    /** Die CI prüft die Mindestversion aus composer.json (8.3) und die aktuelle PHP-Version (8.5). */
    public function test_the_ci_matrix_tests_the_minimum_and_the_latest_php_version(): void
    {
        $workflow = Yaml::parseFile(base_path('.github/workflows/tests.yml'));
        $include = $workflow['jobs']['tests']['strategy']['matrix']['include'] ?? [];

        $this->assertSame([8.3, 8.5], array_column($include, 'php'));
        $this->assertSame([16, 18], array_column($include, 'postgres'));
        $this->assertSame('postgres:${{ matrix.postgres }}', $workflow['jobs']['tests']['services']['postgres']['image'] ?? null);
    }
}
