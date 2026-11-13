<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\Module;
use App\Models\Progress;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CourseProgressService;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Der Kursfortschritt im Admin-Dashboard (Chart „Lernen“), auf den
 * Superadmin-Seiten und in CourseProgressService wird in SQL gezählt
 * (percentsByUserAndCourse) statt alle gefestigten Progress-Zeilen in PHP
 * zu laden. Die Zahlen müssen exakt der bisherigen Formel entsprechen:
 * je Lerner der beste aktive Kurs, round(gefestigt ∩ gültig / gültig × 100),
 * nur aktuell gültige Fragen (aktiv, Revision im Gültigkeitszeitraum), nur
 * Fortschritt dieser Schule. Die Erwartungswerte rechnet der Test
 * unabhängig aus den bekannten Fragenlisten aus.
 */
class AdminDashboardProgressAggregationTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    private array $createdModuleIds = [];

    private array $createdQuestionIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        $this->onAdmin(function () {
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }
            foreach ($this->createdModuleIds as $id) {
                Module::where('id', $id)->delete();
            }
            ContentQuestionRevision::whereIn('question_id', $this->createdQuestionIds)->delete();
            ContentQuestion::whereIn('id', $this->createdQuestionIds)->delete();
        });

        parent::tearDown();
    }

    private function makeQuestion(bool $active = true, ?string $validTo = null): string
    {
        $question = ContentQuestion::create(['content_id' => 'TEST-DPA-Q-'.uniqid('', true), 'language' => 'de-DE', 'active' => $active]);
        $this->createdQuestionIds[] = $question->id;
        if ($validTo !== null) {
            ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => 1,
                'question_type' => 'single_choice',
                'question_text' => 'Abgelaufene Testfrage',
                'valid_from' => '2020-01-01',
                'valid_to' => $validTo,
                'editorial_status' => 'published',
                'rights_status' => 'licensed',
            ]);
        }

        return $question->id;
    }

    /** @param list<string> $questionIds */
    private function makeCourse(string $name, array $questionIds): CourseDefinition
    {
        $course = CourseDefinition::withoutGlobalScopes()->create([
            'tenant_id' => null,
            'code' => 'TEST-DPA-'.uniqid(),
            'name' => $name,
            'course_type' => 'full',
            'status' => 'published',
        ]);
        $this->createdCourseIds[] = $course->id;
        $module = Module::create(['code' => 'TEST-DPA-M-'.uniqid(), 'name' => $name, 'version' => '1', 'status' => 'published']);
        $this->createdModuleIds[] = $module->id;
        $course->modules()->attach($module->id, ['sort_order' => 1, 'required' => true]);
        foreach ($questionIds as $i => $questionId) {
            $module->questions()->attach($questionId, ['sort_order' => $i + 1, 'required' => true]);
        }

        return $course;
    }

    /** @param list<string> $questionIds */
    private function progress(Tenant $tenant, User $user, array $questionIds, string $state = 'gefestigt'): void
    {
        $this->onAdmin(function () use ($tenant, $user, $questionIds, $state) {
            foreach ($questionIds as $questionId) {
                Progress::create([
                    'tenant_id' => $tenant->id, 'user_id' => $user->id, 'question_id' => $questionId,
                    'learning_state' => $state, 'mastery_score' => $state === 'gefestigt' ? 1 : 0.3, 'attempt_count' => 1, 'correct_count' => 1,
                ]);
            }
        });
    }

    /** Unabhängig von der App: Anteil gefestigter gültiger Fragen, gerundet. */
    private static function expectedPercent(array $mastered, array $valid): int
    {
        return (int) round(count(array_intersect($mastered, $valid)) / count($valid) * 100);
    }

    private static function bucket(int $percent): string
    {
        return match (true) {
            $percent <= 20 => '0–20%',
            $percent <= 40 => '21–40%',
            $percent <= 60 => '41–60%',
            $percent <= 80 => '61–80%',
            default => '81–100%',
        };
    }

    public function test_dashboard_progress_distribution_matches_the_reference_formula(): void
    {
        // Kurs A: q0..q7 gültig, q8 deaktiviert, q9 mit abgelaufener Revision.
        // Kurs B (Kombi-artig): teilt q0/q1 mit A, dazu r0..r4.
        [$courseA, $courseB, $q, $r] = $this->onAdmin(function () {
            $q = [];
            for ($i = 0; $i < 8; $i++) {
                $q[] = $this->makeQuestion();
            }
            $q[] = $this->makeQuestion(active: false);
            $q[] = $this->makeQuestion(validTo: now()->subDays(10)->toDateString());
            $r = [];
            for ($i = 0; $i < 5; $i++) {
                $r[] = $this->makeQuestion();
            }

            return [$this->makeCourse('Testkurs A', $q), $this->makeCourse('Testkurs B', [$q[0], $q[1], ...$r]), $q, $r];
        });
        $validA = array_slice($q, 0, 8);
        $validB = [$q[0], $q[1], ...$r];

        $tenant = $this->createTestTenant();
        $otherTenant = $this->createTestTenant('Andere Schule');
        $owner = $this->createTenantUser($tenant, 'owner');

        // [Kurse mit aktivem Zugang, gefestigte Fragen dieser Schule]
        $scenarios = [
            'nur A, inkl. deaktivierter/abgelaufener' => [[$courseA], [$q[0], $q[1], $q[2], $q[3], $q[8], $q[9]]],
            'A und B, B komplett' => [[$courseA, $courseB], [$q[0], $q[1], ...$r]],
            'nur B, Fragen aus A zählen nicht' => [[$courseB], [$r[0], $q[2], $q[3], $q[4], $q[5], $q[6], $q[7]]],
            'A ohne Fortschritt' => [[$courseA], []],
            'A, 3 von 8 (37,5 % → 38)' => [[$courseA], [$q[0], $q[1], $q[2]]],
            'B, 3 von 7' => [[$courseB], [$q[0], $r[1], $r[2]]],
            'A, 6 von 8' => [[$courseA], [$q[0], $q[1], $q[2], $q[3], $q[4], $q[5]]],
        ];

        $expectedPercents = [];
        $learners = [];
        foreach ($scenarios as $label => [$courses, $mastered]) {
            $learner = $this->createTenantUser($tenant, 'learner', ['display_name' => $label]);
            $learners[$label] = $learner;
            foreach ($courses as $course) {
                $this->grantEntitlement($tenant, $learner, $course);
            }
            $this->progress($tenant, $learner, $mastered);
            foreach ($courses as $course) {
                $expectedPercents[$learner->id][$course->id] = self::expectedPercent($mastered, $course->is($courseA) ? $validA : $validB);
            }
        }

        // Nicht-gefestigte Zustände zählen nicht.
        $this->progress($tenant, $learners['A ohne Fortschritt'], [$q[0], $q[1]], 'in_bearbeitung');
        // Abgelaufener Zugang zu B mit vollem B-Fortschritt zählt nicht.
        $this->onAdmin(fn () => Entitlement::create([
            'tenant_id' => $tenant->id, 'user_id' => $learners['A, 3 von 8 (37,5 % → 38)']->id, 'course_id' => $courseB->id,
            'status' => 'active', 'source_type' => 'manual', 'valid_from' => now()->subYear(), 'valid_until' => now()->subDay(),
        ]));
        $this->progress($tenant, $learners['A, 3 von 8 (37,5 % → 38)'], $r);
        // Fortschritt derselben Person in einer anderen Schule zählt nicht.
        $crossTenant = $learners['A, 6 von 8'];
        $this->onAdmin(fn () => TenantUser::create(['tenant_id' => $otherTenant->id, 'user_id' => $crossTenant->id, 'role' => 'learner', 'status' => 'active']));
        $this->progress($otherTenant, $crossTenant, $validA);
        $this->grantEntitlement($otherTenant, $crossTenant, $courseA);

        $expectedBuckets = ['0–20%' => 0, '21–40%' => 0, '41–60%' => 0, '61–80%' => 0, '81–100%' => 0];
        foreach ($expectedPercents as $byCourse) {
            $expectedBuckets[self::bucket(max($byCourse))]++;
        }
        // Plausibilität der Szenarien selbst (unabhängig von der App).
        $this->assertSame(['0–20%' => 2, '21–40%' => 1, '41–60%' => 2, '61–80%' => 1, '81–100%' => 1], $expectedBuckets);

        $response = $this->actingAsInTenant($owner, $tenant)->get('/admin');
        $response->assertOk();
        $this->assertSame(count($scenarios), $response->viewData('learningPhaseLearnerCount'));
        $actual = collect($response->viewData('progressDistribution'))->mapWithKeys(fn ($row) => [$row['label'] => $row['count']])->all();
        $this->assertSame($expectedBuckets, $actual);

        // Dieselben Werte aus der Service-Methode (SQL) und dem Einzelpfad.
        $this->asTenant($tenant, function () use ($tenant, $courseA, $courseB, $learners, $expectedPercents) {
            $service = app(CourseProgressService::class);
            $all = $service->percentsByUserAndCourse($tenant, collect([$courseA, $courseB]), collect($learners)->pluck('id'));
            foreach ($expectedPercents as $userId => $byCourse) {
                foreach ($byCourse as $courseId => $percent) {
                    $this->assertSame($percent, $all[$userId][$courseId]);
                    $course = $courseId === $courseA->id ? $courseA : $courseB;
                    $this->assertSame($percent, $service->overallPercent($course->fresh(), $tenant, User::find($userId)));
                    $this->assertSame([$userId => $percent], $service->overallPercents($course->fresh(), $tenant, [$userId]));
                }
            }
        });

        // Superadmin-Seiten nutzen dieselbe Implementierung.
        // (Ohne den Dev-Tenant-Override aus der Admin-Anfrage oben.)
        $this->flushSession();
        $superadmin = $this->createSuperAdmin();
        $tenantShow = $this->actingAs($superadmin)->get("/superadmin/tenants/{$tenant->id}")->assertOk();
        // Hier auch abgelaufene Zugänge (die Liste zeigt alle Freischaltungen).
        $masteredByUser = collect($scenarios)->mapWithKeys(fn ($scenario, $label) => [$learners[$label]->id => $scenario[1]])->all();
        $masteredByUser[$learners['A, 3 von 8 (37,5 % → 38)']->id] = [...$masteredByUser[$learners['A, 3 von 8 (37,5 % → 38)']->id], ...$r];
        $validByCourse = [$courseA->id => $validA, $courseB->id => $validB];
        $this->assertCount(count($scenarios) + 2, $tenantShow->viewData('results'));
        foreach ($tenantShow->viewData('results') as $row) {
            $entitlement = $row['entitlement'];
            $this->assertSame(self::expectedPercent($masteredByUser[$entitlement->user_id], $validByCourse[$entitlement->course_id]), $row['percent']);
        }
        $userShow = $this->actingAs($superadmin)->get("/superadmin/users/{$crossTenant->id}")->assertOk();
        $byTenant = collect($userShow->viewData('results'))->mapWithKeys(fn ($row) => [$row['entitlement']->tenant_id => $row['percent']]);
        $this->assertSame(self::expectedPercent([$q[0], $q[1], $q[2], $q[3], $q[4], $q[5]], $validA), $byTenant[$tenant->id]);
        $this->assertSame(100, $byTenant[$otherTenant->id]);
    }
}
