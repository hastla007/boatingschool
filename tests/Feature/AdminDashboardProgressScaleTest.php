<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Audit ARC-1: das Fortschritts-Diagramm auf /admin lud alle gefestigten
 * Progress-Zeilen der Lernenden als Modelle (1000 Lernende ≈ 438 MB, darüber
 * „memory exhausted“). Jetzt zählt die Datenbank je Kurs -- die Buckets
 * müssen exakt gleich bleiben, Speicher und Abfragen begrenzt.
 */
class AdminDashboardProgressScaleTest extends TestCase
{
    use InteractsWithTenants;

    /** @return list<string> gültige Fragen-IDs des Kurses */
    private function validQuestionIds(CourseDefinition $course): array
    {
        return $this->onAdmin(fn () => ContentQuestion::currentlyValidIds(
            $course->loadMissing('modules.questions')->modules->flatMap(fn ($m) => $m->questions)
        )->values()->all());
    }

    /** Lernende samt Freischaltungen und gefestigten Fragen in Sammel-Inserts (schnell auch für Hunderte). */
    private function seedLearners(Tenant $tenant, array $plan): array
    {
        $userIds = [];
        DB::connection('pgsql_admin')->transaction(function () use ($tenant, $plan, &$userIds) {
            $db = DB::connection('pgsql_admin');
            $hash = bcrypt('password');
            foreach (array_chunk($plan, 200) as $chunk) {
                $users = $memberships = $entitlements = [];
                foreach ($chunk as $row) {
                    $id = (string) Str::uuid7();
                    $userIds[] = $id;
                    $users[] = ['id' => $id, 'email' => $id.'@example.test', 'display_name' => 'Skalierung', 'password' => $hash, 'status' => 'active', 'email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now()];
                    $memberships[] = ['tenant_id' => $tenant->id, 'user_id' => $id, 'role' => 'learner', 'status' => 'active', 'created_at' => now()];
                    foreach ($row['courses'] as $courseId) {
                        $entitlements[] = ['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'user_id' => $id, 'course_id' => $courseId, 'status' => 'active', 'source_type' => 'manual', 'valid_from' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()];
                    }
                }
                $db->table('app_user')->insert($users);
                $db->table('tenant_user')->insert($memberships);
                $db->table('entitlement')->insert($entitlements);
            }
            foreach ($plan as $i => $row) {
                $progress = array_map(fn ($questionId) => [
                    'tenant_id' => $tenant->id, 'user_id' => $userIds[$i], 'question_id' => $questionId,
                    'learning_state' => 'gefestigt', 'mastery_score' => 1, 'attempt_count' => 1, 'correct_count' => 1,
                ], $row['mastered']);
                foreach (array_chunk($progress, 1000) as $part) {
                    $db->table('progress')->insert($part);
                }
            }
        });
        $this->createdUserIds = array_merge($this->createdUserIds, $userIds);

        return $userIds;
    }

    public function test_progress_buckets_stay_exactly_the_same(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $src = $this->existingCourse('SRC');
        $ubi = $this->existingCourse('UBI');
        $srcIds = $this->validQuestionIds($src);
        $ubiIds = $this->validQuestionIds($ubi);
        $share = fn (array $ids, float $ratio) => array_slice($ids, 0, (int) floor(count($ids) * $ratio));
        // Inaktive Frage zählt nie als gefestigt (F7) -- auch nicht über die Aggregation.
        $inactive = $this->onAdmin(fn () => ContentQuestion::whereNotIn('id', array_merge($srcIds, $ubiIds))->value('id'));

        $this->seedLearners($tenant, [
            ['courses' => [$src->id], 'mastered' => []],                                   // 0 %
            ['courses' => [$src->id], 'mastered' => $share($srcIds, 0.30)],               // 30 %
            ['courses' => [$src->id], 'mastered' => array_merge($share($srcIds, 0.205), [$inactive])], // ~21 % (gerundet)
            ['courses' => [$src->id, $ubi->id], 'mastered' => array_merge($share($srcIds, 0.10), $share($ubiIds, 0.70))], // Maximum: 70 %
            ['courses' => [$ubi->id], 'mastered' => $share($ubiIds, 0.95)],               // 95 %
            ['courses' => [$ubi->id], 'mastered' => $share($ubiIds, 0.50)],               // 50 %
        ]);

        // Erwartung mit derselben Formel wie overallPercent(): gerundeter Anteil gültiger Fragen.
        $percent = fn (array $mastered, array $valid) => (int) round(count(array_intersect($mastered, $valid)) / max(count($valid), 1) * 100);
        $expected = ['0–20%' => 0, '21–40%' => 0, '41–60%' => 0, '61–80%' => 0, '81–100%' => 0];
        foreach ([
            0,
            $percent($share($srcIds, 0.30), $srcIds),
            $percent($share($srcIds, 0.205), $srcIds),
            max($percent($share($srcIds, 0.10), $srcIds), $percent($share($ubiIds, 0.70), $ubiIds)),
            $percent($share($ubiIds, 0.95), $ubiIds),
            $percent($share($ubiIds, 0.50), $ubiIds),
        ] as $p) {
            $expected[match (true) {
                $p <= 20 => '0–20%', $p <= 40 => '21–40%', $p <= 60 => '41–60%', $p <= 80 => '61–80%', default => '81–100%',
            }]++;
        }

        $response = $this->actingAsInTenant($owner, $tenant)->get(route('admin.dashboard'))->assertOk();
        $response->assertViewHas('learningPhaseLearnerCount', 6);
        $response->assertViewHas('progressDistribution', fn ($distribution) => $distribution->pluck('count', 'label')->all() === $expected);
    }

    public function test_dashboard_memory_and_queries_stay_bounded_with_many_learners_and_mastered_questions(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SBF-SEE');
        $valid = $this->validQuestionIds($course);
        $this->assertGreaterThanOrEqual(200, count($valid));
        $questionIds = array_slice($valid, 0, 200);
        $this->seedLearners($tenant, array_fill(0, 300, ['courses' => [$course->id], 'mastered' => $questionIds]));
        $p = (int) round(200 / count($valid) * 100);
        $bucket = match (true) {
            $p <= 20 => '0–20%', $p <= 40 => '21–40%', $p <= 60 => '41–60%', $p <= 80 => '61–80%', default => '81–100%',
        };

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        gc_collect_cycles();
        $before = memory_get_usage();
        memory_reset_peak_usage();
        $response = $this->actingAsInTenant($owner, $tenant)->get(route('admin.dashboard'))->assertOk();
        $peak = memory_get_peak_usage() - $before;

        $response->assertViewHas('progressDistribution', fn ($d) => $d->firstWhere('label', $bucket)['count'] === 300);
        $this->assertLessThan(30 * 1024 * 1024, $peak, 'Speicherspitze für /admin: '.round($peak / 1048576, 1).' MB');
        $this->assertLessThan(100, $queries, "Abfragen für /admin: {$queries}");
    }

    /** Abfragen, die einzelne Kurse/Produkte per ID nachladen (find()). */
    private function lookupQueries(Tenant $tenant, $owner): int
    {
        $lookups = 0;
        DB::listen(function ($query) use (&$lookups) {
            if (preg_match('/from "(course_definition|product)" where "\1"\."id" = \?/', $query->sql)) {
                $lookups++;
            }
        });
        $this->actingAsInTenant($owner, $tenant)->get(route('admin.dashboard'))->assertOk();

        return $lookups;
    }

    /** Audit ARC-6: die Bestandsübersicht der Codes lud Kurs/Produkt je Zeile einzeln nach. */
    public function test_coupon_summary_does_not_query_per_course_or_product(): void
    {
        $coupons = function (Tenant $tenant, array $courseCodes, int $products) {
            $this->onAdmin(function () use ($tenant, $courseCodes, $products) {
                foreach ($courseCodes as $code) {
                    Coupon::create(['code' => 'ARC6-'.Str::upper(Str::random(10)), 'tenant_id' => $tenant->id, 'course_id' => $this->existingCourse($code)->id]);
                }
                foreach (Product::orderBy('id')->limit($products)->pluck('id') as $productId) {
                    Coupon::create(['code' => 'ARC6-'.Str::upper(Str::random(10)), 'tenant_id' => $tenant->id, 'product_id' => $productId]);
                }
            });
        };
        $large = $this->createTestTenant();
        $largeOwner = $this->createTenantUser($large, 'owner');
        $coupons($large, ['SRC', 'UBI', 'SKN', 'FKN', 'SBF-SEE'], 3);

        $this->assertSame(0, $this->lookupQueries($large, $largeOwner));
    }

    /** Audit ARC-1: Produktkäufe nur für die Teilnehmer der angezeigten Seite laden. */
    public function test_product_purchases_are_loaded_only_for_the_current_participants_page(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $userIds = $this->seedLearners($tenant, array_fill(0, 30, ['courses' => [], 'mastered' => []]));
        $productId = $this->onAdmin(fn () => Product::value('id'));
        DB::connection('pgsql_admin')->table('product_purchase')->insert(array_map(fn ($id) => ['tenant_id' => $tenant->id, 'user_id' => $id, 'product_id' => $productId, 'source_type' => 'manual'], $userIds));

        $response = $this->actingAsInTenant($owner, $tenant)->get(route('admin.dashboard'))->assertOk();
        $pageIds = $response->viewData('participants')->getCollection()->pluck('user_id')->sort()->values()->all();
        $this->assertCount(25, $pageIds);
        $this->assertSame($pageIds, $response->viewData('productPurchases')->keys()->sort()->values()->all());
    }
}
