<?php

namespace Tests\Feature\Api;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Der App-Vertrag (docs/api/openapi-mobile-v1.yaml) und die tatsächlichen
 * Routen dürfen nicht auseinanderlaufen: jeder Endpunkt steht in beiden,
 * mit denselben Pfadparametern, Anmelde-, Schul- und Idempotenz-Pflichten,
 * und jeder Fehlercode im Code steht in Problem.code und in der
 * Fehlertabelle der README. Rein statisch (ohne Datenbankzugriff).
 */
class OpenApiContractTest extends TestCase
{
    private const PREFIX = 'api/v1/';

    /** @var array<string, mixed>|null */
    private static ?array $spec = null;

    public function test_every_api_route_is_documented_and_every_documented_path_exists(): void
    {
        $spec = $this->spec();
        $documented = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $documented[] = strtoupper($method).' '.$path;
                }
            }
        }

        $actual = collect($this->apiRoutes())
            ->flatMap(fn (RoutingRoute $r) => collect($this->methods($r))->map(fn ($m) => $m.' '.$this->path($r)))
            ->values()->all();

        sort($documented);
        sort($actual);
        $this->assertSame($actual, $documented, 'Routen und OpenAPI-Datei weichen voneinander ab.');
        $this->assertSame('3.1.0', $spec['openapi']);
    }

    /**
     * Pfadparameter, Anmeldung (security: []), X-Tenant-Slug und
     * Idempotency-Key je Operation wie die Middleware der Route.
     */
    public function test_parameters_and_middleware_of_every_route_match_the_contract(): void
    {
        $spec = $this->spec();
        $problems = [];

        foreach ($this->apiRoutes() as $route) {
            $path = $this->path($route);
            $middleware = $route->gatherMiddleware();
            foreach ($this->methods($route) as $method) {
                $label = $method.' '.$path;
                $operation = $spec['paths'][$path][strtolower($method)] ?? null;
                if ($operation === null) {
                    continue; // meldet der Routen-Test
                }
                $parameters = array_map(fn ($p) => $this->resolve($p), array_merge(
                    $spec['paths'][$path]['parameters'] ?? [],
                    $operation['parameters'] ?? [],
                ));
                $has = fn (string $in, string $name) => collect($parameters)
                    ->contains(fn ($p) => $p['in'] === $in && $p['name'] === $name && ($p['required'] ?? false));

                $documentedPath = collect($parameters)->where('in', 'path')->pluck('name')->sort()->values()->all();
                $routePath = collect($route->parameterNames())->sort()->values()->all();
                if ($documentedPath !== $routePath) {
                    $problems[] = "{$label}: Pfadparameter [".implode(',', $documentedPath).'] statt ['.implode(',', $routePath).']';
                }
                foreach ($route->parameterNames() as $name) {
                    if (in_array($name, $documentedPath, true) && ! $has('path', $name)) {
                        $problems[] = "{$label}: Pfadparameter {$name} nicht als required markiert";
                    }
                }

                $public = ! in_array('api.auth', $middleware, true);
                if ($public !== (($operation['security'] ?? null) === [])) {
                    $problems[] = "{$label}: ".($public ? 'öffentlich, aber ohne security: []' : 'braucht ein Token, steht aber mit security: []');
                }

                $tenant = in_array('api.tenant', $middleware, true);
                if ($tenant !== $has('header', 'X-Tenant-Slug')) {
                    $problems[] = "{$label}: X-Tenant-Slug ".($tenant ? 'fehlt als Pflicht-Header' : 'ist fälschlich Pflicht');
                }

                $idempotent = in_array('api.idempotent', $middleware, true);
                if ($idempotent !== $has('header', 'Idempotency-Key')) {
                    $problems[] = "{$label}: Idempotency-Key ".($idempotent ? 'fehlt als Pflicht-Header' : 'ist fälschlich Pflicht');
                }
            }
        }

        $this->assertSame([], $problems, "Parameter bzw. Middleware weichen vom Vertrag ab:\n".implode("\n", $problems));
    }

    /**
     * Problem.code ist genau die Menge der Codes, die die Mobile-API werfen
     * kann: ApiProblem-Aufrufe (zweites Argument) und die Abbildung der
     * Framework-Fehler in ProblemResponse. Die Händler-Schnittstelle
     * (Commerce) hat einen eigenen Vertrag (docs/commerce).
     */
    public function test_every_error_code_is_in_the_contract_and_the_readme(): void
    {
        $enum = $this->spec()['components']['schemas']['Problem']['properties']['code']['enum'];
        $thrown = $this->thrownProblemCodes();

        $this->assertSame([], array_values(array_diff($thrown, $enum)), 'Fehlercodes fehlen in Problem.code (docs/api/openapi-mobile-v1.yaml).');
        $this->assertSame([], array_values(array_diff($enum, $thrown)), 'Problem.code enthält Codes, die der Code nicht mehr wirft.');
        $this->assertSame(count($enum), count(array_unique($enum)), 'Problem.code enthält doppelte Einträge.');

        $readme = (string) file_get_contents(base_path('docs/api/README.md'));
        $start = strpos($readme, "\n## 11. Fehlercodes\n");
        $this->assertNotFalse($start, 'Abschnitt „11. Fehlercodes“ fehlt in docs/api/README.md.');
        $section = preg_split('/^## /m', substr($readme, $start + 1))[1];
        preg_match_all('/`([A-Z][A-Z0-9_]*[A-Z0-9])`/', $section, $matches);
        $this->assertSame([], array_values(array_diff($thrown, $matches[1])), 'Fehlercodes fehlen in der Tabelle „11. Fehlercodes“ der README.');
    }

    public function test_every_reference_in_the_contract_resolves(): void
    {
        $spec = $this->spec();
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

    // ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function spec(): array
    {
        return self::$spec ??= Yaml::parseFile(base_path('docs/api/openapi-mobile-v1.yaml'));
    }

    /** @return list<RoutingRoute> */
    private function apiRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $r) => str_starts_with($r->uri(), self::PREFIX))->values()->all();
    }

    /** @return list<string> */
    private function methods(RoutingRoute $route): array
    {
        return array_values(array_diff($route->methods(), ['HEAD']));
    }

    private function path(RoutingRoute $route): string
    {
        return '/'.substr($route->uri(), strlen(self::PREFIX));
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function resolve(array $node): array
    {
        while (isset($node['$ref'])) {
            $target = $this->spec();
            foreach (explode('/', substr($node['$ref'], 2)) as $part) {
                $target = $target[$part];
            }
            $node = $target;
        }

        return $node;
    }

    /** @return list<string> sortiert, ohne Doppelte */
    private function thrownProblemCodes(): array
    {
        $codes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = $file->getPathname();
            // Commerce und Workflow-Vertrag haben eigene Verträge (docs/commerce, docs/workflow).
            if ($file->getExtension() !== 'php' || str_contains($path, DIRECTORY_SEPARATOR.'Commerce'.DIRECTORY_SEPARATOR)
                || str_contains($path, DIRECTORY_SEPARATOR.'Workflow'.DIRECTORY_SEPARATOR)) {
                continue;
            }
            $source = (string) file_get_contents($path);
            if (! str_contains($source, 'ApiProblem')) {
                continue;
            }
            $isProblemClass = $path === app_path('Api'.DIRECTORY_SEPARATOR.'ApiProblem.php');
            array_push($codes, ...$this->constructorCodes($source, $path, $isProblemClass));
            if ($path === app_path('Api'.DIRECTORY_SEPARATOR.'ProblemResponse.php')) {
                // Abbildung der Framework-Fehler: alle Codes stehen als Literale in describe().
                foreach (token_get_all($source) as $token) {
                    if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && preg_match("/^'([A-Z]+(?:_[A-Z]+)*)'$/", $token[1], $m)) {
                        $codes[] = $m[1];
                    }
                }
            }
        }

        $this->assertContains('RESOURCE_NOT_FOUND', $codes, 'Die Suche nach Fehlercodes hat nichts gefunden.');
        $codes = array_values(array_unique($codes));
        sort($codes);

        return $codes;
    }

    /**
     * Zweites Argument jedes `new ApiProblem(…)` (in ApiProblem selbst
     * `new self(…)`); es muss ein String-Literal sein, sonst ließe sich
     * der Code nicht statisch abgleichen.
     *
     * @return list<string>
     */
    private function constructorCodes(string $source, string $path, bool $isProblemClass): array
    {
        $tokens = array_values(array_filter(token_get_all($source),
            fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $codes = [];
        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_NEW || ! is_array($tokens[$i + 1] ?? null)) {
                continue;
            }
            $class = ltrim($tokens[$i + 1][1], '\\');
            $matches = $class === 'ApiProblem' || $class === 'App\\Api\\ApiProblem' || ($isProblemClass && $class === 'self');
            if (! $matches || ($tokens[$i + 2] ?? null) !== '(') {
                continue;
            }
            $depth = 0;
            $argument = 0;
            $second = [];
            for ($j = $i + 2, $n = count($tokens); $j < $n; $j++) {
                $t = $tokens[$j];
                if (in_array($t, ['(', '[', '{'], true) || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif (in_array($t, [')', ']', '}'], true)) {
                    if (--$depth === 0) {
                        break;
                    }
                } elseif ($t === ',' && $depth === 1) {
                    $argument++;
                } elseif ($depth === 1 && $argument === 1) {
                    $second[] = $t;
                }
            }
            $this->assertTrue(
                count($second) === 1 && is_array($second[0]) && $second[0][0] === T_CONSTANT_ENCAPSED_STRING,
                "Fehlercode in {$path}:{$token[2]} ist kein String-Literal (für den Vertragsabgleich nötig)."
            );
            $codes[] = trim($second[0][1], '\'"');
        }

        return $codes;
    }
}
