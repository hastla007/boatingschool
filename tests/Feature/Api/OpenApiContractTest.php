<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Der App-Vertrag (docs/api/openapi-mobile-v1.yaml) und die tatsächlichen
 * Routen dürfen nicht auseinanderlaufen: jeder Endpunkt steht in beiden.
 */
class OpenApiContractTest extends TestCase
{
    public function test_every_api_route_is_documented_and_every_documented_path_exists(): void
    {
        $spec = Yaml::parseFile(base_path('docs/api/openapi-mobile-v1.yaml'));
        $documented = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[] = strtoupper($method).' '.$path;
            }
        }

        $actual = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/'))
            ->flatMap(fn ($r) => collect($r->methods())->reject(fn ($m) => $m === 'HEAD')
                ->map(fn ($m) => $m.' /'.substr($r->uri(), strlen('api/v1/'))))
            ->values()->all();

        sort($documented);
        sort($actual);
        $this->assertSame($actual, $documented, 'Routen und OpenAPI-Datei weichen voneinander ab.');
        $this->assertSame('3.1.0', $spec['openapi']);
    }

    public function test_every_reference_in_the_contract_resolves(): void
    {
        $spec = Yaml::parseFile(base_path('docs/api/openapi-mobile-v1.yaml'));
        $refs = [];
        array_walk_recursive($spec, function ($value, $key) use (&$refs) {
            if ($key === '$ref') {
                $refs[] = $value;
            }
        });

        $this->assertNotEmpty($refs);
        foreach (array_unique($refs) as $ref) {
            $node = $spec;
            foreach (explode('/', substr($ref, 2)) as $part) {
                $this->assertIsArray($node, "Verweis {$ref} läuft ins Leere");
                $this->assertArrayHasKey($part, $node, "Verweis {$ref} läuft ins Leere");
                $node = $node[$part];
            }
        }
    }
}
