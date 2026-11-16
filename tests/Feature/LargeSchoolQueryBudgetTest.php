<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\InactiveLearnerController;
use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Lastmessung große Bootsschule (tests/Perf/LargeSchoolProbeTest.php: 1200
 * Lernende, 360 000 Versuche, 5000 Coupons): Abfrage-Budgets und Grenzen
 * der dabei behobenen Stellen.
 *
 * - /admin: „Kürzlich gebuchte Kurse & Produkte“ zeigt höchstens
 *   RECENT_BOOKINGS_LIMIT Einträge (vorher jede Buchung der Woche, 900
 *   Zeilen / ~550 KB); die Teilnehmerliste für „Zuweisen“ wird nur geladen,
 *   wenn die Coupon-Seite einen zuweisbaren Code zeigt; jeder Reiter
 *   (?tab=…) wird einzeln berechnet und hat ein eigenes Budget.
 * - /admin/inactive: seitenweise (vorher alle ~1000 Personen, 1,2 MB).
 * - /admin/statistics, /superadmin: Zählungen ohne count(distinct) über
 *   jede einzelne Antwort, Kurse/Konten nicht mehr als Modelle je Zugang.
 *
 * Wie AdminAndTenantShowPerformanceTest vergleichen die Budgets zwei
 * verschieden große Schulen: die Differenz darf nicht mit der Zahl der
 * Lernenden wachsen (N+1), dazu je eine absolute Obergrenze.
 */
class LargeSchoolQueryBudgetTest extends TestCase
{
    use InteractsWithTenants;

    /** @var list<string> */
    private array $couponIds = [];

    protected function tearDown(): void
    {
        $this->onAdmin(fn () => Coupon::whereIn('id', $this->couponIds)->delete());
        parent::tearDown();
    }

    /**
     * Viele Lernende auf einmal (Mengen-Insert statt je Person mehrerer
     * Eloquent-Aufrufe), je mit laufendem Zugang zu $course.
     *
     * @return list<string> Nutzer-IDs
     */
    private function createLearners(Tenant $tenant, int $count, CourseDefinition $course, array $entitlement = []): array
    {
        return $this->onAdmin(function () use ($tenant, $count, $course, $entitlement) {
            $hash = bcrypt('password');
            $ids = [];
            foreach (range(1, $count) as $i) {
                $id = (string) Str::uuid();
                $ids[] = $id;
                $this->createdUserIds[] = $id;
                DB::table('app_user')->insert([
                    'id' => $id, 'email' => $id.'@example.test', 'display_name' => "Lernende {$i}", 'password' => $hash,
                    'status' => 'active', 'email_verified_at' => now(), 'age_confirmed_at' => now(),
                ]);
            }
            DB::table('tenant_user')->insert(array_map(fn ($id) => ['tenant_id' => $tenant->id, 'user_id' => $id, 'role' => 'learner', 'status' => 'active'], $ids));
            DB::table('entitlement')->insert(array_map(fn ($id) => $entitlement + [
                'tenant_id' => $tenant->id, 'user_id' => $id, 'course_id' => $course->id, 'status' => 'active',
                'source_type' => 'manual', 'valid_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(),
            ], $ids));

            return $ids;
        });
    }

    /** @param  list<string>  $userIds */
    private function createAttempts(Tenant $tenant, array $userIds, array $daysAgo): void
    {
        $this->onAdmin(function () use ($tenant, $userIds, $daysAgo) {
            $revision = DB::table('content_question_revision')->where('editorial_status', 'published')->first();
            $rows = [];
            foreach ($userIds as $userId) {
                foreach ($daysAgo as $days) {
                    $rows[] = [
                        'tenant_id' => $tenant->id, 'user_id' => $userId, 'question_id' => $revision->question_id, 'revision_id' => $revision->id,
                        'correct' => true, 'context' => 'smarttrainer', 'created_at' => now()->subDays($days),
                    ];
                }
            }
            DB::table('attempt')->insert($rows);
        });
    }

    private bool $counting = false;

    private int $queryCount = 0;

    private bool $listening = false;

    private function countQueries(callable $call): int
    {
        if (! $this->listening) {
            $this->listening = true;
            DB::listen(function ($query) {
                // RESET app.* aus den terminating()-Callbacks summiert sich im
                // Test über alle Requests derselben App-Instanz auf.
                if ($this->counting && ! str_starts_with($query->sql, 'RESET app.')) {
                    $this->queryCount++;
                }
            });
        }
        $this->queryCount = 0;
        $this->counting = true;
        try {
            $call();
        } finally {
            $this->counting = false;
        }

        return $this->queryCount;
    }

    public function test_admin_dashboard_caps_recent_bookings_with_constant_queries(): void
    {
        $course = $this->existingCourse('SRC');
        $limit = AdminDashboardController::RECENT_BOOKINGS_LIMIT;
        $measure = function (int $learners) use ($course) {
            $tenant = $this->createTestTenant();
            $owner = $this->createTenantUser($tenant, 'owner');
            $this->createLearners($tenant, $learners, $course);
            $response = null;
            $queries = $this->countQueries(function () use (&$response, $owner, $tenant) {
                $response = $this->actingAsInTenant($owner, $tenant)->get('/admin')->assertOk();
            });

            return [$queries, $response];
        };

        [$small, $smallResponse] = $measure(3);
        [$large, $largeResponse] = $measure($limit + 15);

        $this->assertCount(3, $smallResponse->viewData('recentBookings'));
        $this->assertSame(3, $smallResponse->viewData('recentBookingsTotal'));
        $this->assertStringNotContainsString('weitere in den letzten 7 Tagen', $smallResponse->getContent());

        $this->assertCount($limit, $largeResponse->viewData('recentBookings'));
        $this->assertSame($limit + 15, $largeResponse->viewData('recentBookingsTotal'));
        $largeResponse->assertSee('… und 15 weitere in den letzten 7 Tagen.');

        $this->assertLessThanOrEqual(self::TAB_QUERY_BUDGETS['dashboard'], $large, "/admin: {$large} Abfragen");
        $this->assertLessThanOrEqual(2, $large - $small, "/admin: {$large} Abfragen bei ".($limit + 15)." Lernenden statt {$small} bei 3 -- wächst mit den Lernenden (N+1?).");
    }

    public function test_admin_dashboard_loads_the_assign_list_only_for_an_assignable_coupon(): void
    {
        $course = $this->existingCourse('SRC');
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $this->createLearners($tenant, 3, $course);

        $without = $this->actingAsInTenant($owner, $tenant)->get('/admin?tab=coupons')->assertOk();
        $this->assertCount(0, $without->viewData('allParticipants'));
        $this->assertStringNotContainsString('id="coupon-assign-options-template"', $without->getContent());

        $this->couponIds[] = $this->onAdmin(fn () => Coupon::create(['code' => 'LSQB'.Str::upper(Str::random(10)), 'course_id' => $course->id, 'tenant_id' => $tenant->id])->id);
        $with = $this->actingAsInTenant($owner, $tenant)->get('/admin?tab=coupons')->assertOk();
        $this->assertCount(3, $with->viewData('allParticipants'));
        $with->assertSee('id="coupon-assign-options-template"', false);
        $with->assertSee('Lernende 1');

        // Andere Reiter laden die Liste (und die Coupons) gar nicht.
        $dashboard = $this->actingAsInTenant($owner, $tenant)->get('/admin')->assertOk();
        $dashboard->assertViewMissing('allParticipants');
        $dashboard->assertViewMissing('coupons');
        $this->assertStringNotContainsString('id="coupon-assign-options-template"', $dashboard->getContent());
    }

    /**
     * Abfrage-Budget je Reiter von /admin (gemessen + 3, ohne die zwei
     * RESET-Abfragen je Request; davon ~15 Sockel aus Middleware/Layout).
     * Nur der aktive Reiter wird berechnet -- bis dahin kostete JEDER
     * Aufruf die Summe aller Reiter (Messsonde mit 1200 Lernenden: 90
     * Abfragen, ~720 KB HTML, egal welcher Reiter).
     */
    private const TAB_QUERY_BUDGETS = [
        'dashboard' => 41,
        'participants' => 36,
        'praxis-anmeldungen' => 22,
        'willkommensbox' => 20,
        'coupons' => 31,
        'courses' => 23,
        'learning-groups' => 20,
        'orte' => 23,
        'branding' => 21,
        'addons' => 22,
        'course-promo-banners' => 21,
    ];

    public function test_each_admin_tab_stays_within_its_query_budget_independent_of_school_size(): void
    {
        $this->assertSame(array_keys(AdminDashboardController::TABS), array_keys(self::TAB_QUERY_BUDGETS));
        $course = $this->existingCourse('SRC');
        $measure = function (int $learners) use ($course) {
            $tenant = $this->createTestTenant();
            $owner = $this->createTenantUser($tenant, 'owner');
            $this->createLearners($tenant, $learners, $course);
            // Ein zuweisbarer Code: der Zugangscodes-Reiter lädt dann auch die Auswahl „Zuweisen“.
            $this->couponIds[] = $this->onAdmin(fn () => Coupon::create(['code' => 'LSQB'.Str::upper(Str::random(10)), 'course_id' => $course->id, 'tenant_id' => $tenant->id])->id);
            $counts = [];
            foreach (array_keys(AdminDashboardController::TABS) as $tab) {
                $counts[$tab] = $this->countQueries(function () use ($owner, $tenant, $tab) {
                    $this->actingAsInTenant($owner, $tenant)->get('/admin?tab='.$tab)->assertOk()->assertViewHas('activeTab', $tab);
                });
            }

            return $counts;
        };

        $small = $measure(3);
        // Mehr als eine Seite der Teilnehmerliste (25).
        $large = $measure(40);

        foreach (self::TAB_QUERY_BUDGETS as $tab => $budget) {
            $this->assertLessThanOrEqual($budget, $large[$tab], "/admin?tab={$tab}: {$large[$tab]} Abfragen (Budget {$budget})");
            $this->assertLessThanOrEqual(1, $large[$tab] - $small[$tab], "/admin?tab={$tab}: {$large[$tab]} Abfragen bei 40 Lernenden statt {$small[$tab]} bei 3 -- wächst mit den Lernenden (N+1?).");
        }
    }

    public function test_inactive_learners_are_paginated_with_constant_queries(): void
    {
        $course = $this->existingCourse('SRC');
        $perPage = InactiveLearnerController::PER_PAGE;
        $measure = function (int $learners) use ($course) {
            $tenant = $this->createTestTenant();
            $owner = $this->createTenantUser($tenant, 'owner');
            $ids = $this->createLearners($tenant, $learners, $course);
            // Eine Person war gerade aktiv und zählt nicht als inaktiv.
            $this->createAttempts($tenant, [$ids[0]], [1]);
            $response = null;
            $queries = $this->countQueries(function () use (&$response, $owner, $tenant) {
                $response = $this->actingAsInTenant($owner, $tenant)->get('/admin/inactive?days=14')->assertOk();
            });

            return [$queries, $response, $tenant, $owner];
        };

        [$small] = $measure(4);
        [$large, $response, $tenant, $owner] = $measure($perPage + 11);

        $this->assertLessThan(25, $large, "/admin/inactive: {$large} Abfragen");
        $this->assertLessThanOrEqual(2, $large - $small, "/admin/inactive: {$large} Abfragen statt {$small} -- wächst mit den Lernenden (N+1?).");

        $this->assertCount($perPage, $response->viewData('rows')->items());
        $this->assertSame($perPage + 10, $response->viewData('total'));
        $response->assertSee(($perPage + 10).' Personen');
        $response->assertSee('Alle erinnern ('.($perPage + 10).')');

        $page2 = $this->actingAsInTenant($owner, $tenant)->get('/admin/inactive?days=14&page=2')->assertOk();
        $this->assertCount(10, $page2->viewData('rows')->items());
        $page2->assertSee('Alle erinnern ('.($perPage + 10).')');
    }

    public function test_school_statistics_query_count_does_not_scale_with_learners(): void
    {
        $course = $this->existingCourse('SRC');
        $measure = function (int $learners) use ($course) {
            $tenant = $this->createTestTenant();
            $owner = $this->createTenantUser($tenant, 'owner');
            $ids = $this->createLearners($tenant, $learners, $course);
            $this->createAttempts($tenant, $ids, [1, 2, 40, 70]);
            $response = null;
            $queries = $this->countQueries(function () use (&$response, $owner, $tenant) {
                $response = $this->actingAsInTenant($owner, $tenant)->get('/admin/statistics')->assertOk();
            });

            return [$queries, $response, $learners];
        };

        [$small] = $measure(3);
        [$large, $response, $learners] = $measure(20);

        $this->assertLessThan(35, $large, "/admin/statistics: {$large} Abfragen");
        $this->assertLessThanOrEqual(2, $large - $small, "/admin/statistics: {$large} Abfragen statt {$small} -- wächst mit den Lernenden (N+1?).");
        $this->assertSame($learners, $response->viewData('activeLearners30'));
        $this->assertSame($learners * 2, $response->viewData('answers30'));
        $this->assertSame($learners, $response->viewData('withAccess'));
        $row = $response->viewData('courses')->sole();
        $this->assertSame([$course->name, $learners, 0], [$row['name'], $row['learners'], $row['average']]);
    }

    public function test_superadmin_dashboard_counts_active_learners_with_constant_queries(): void
    {
        $course = $this->existingCourse('SRC');
        $superadmin = $this->createSuperAdmin();
        $measure = function (int $learners) use ($course, $superadmin) {
            $tenant = $this->createTestTenant();
            $ids = $this->createLearners($tenant, $learners, $course);
            // Mehrere Antworten je Person zählen einmal; eine alte Antwort gar nicht.
            $this->createAttempts($tenant, $ids, [1, 3, 5]);
            $this->createAttempts($tenant, [$this->createLearners($tenant, 1, $course)[0]], [45]);
            $response = null;
            $queries = $this->countQueries(function () use (&$response, $superadmin) {
                $this->app['auth']->forgetGuards();
                $response = $this->actingAs($superadmin)->get('/superadmin')->assertOk();
            });

            return [$queries, $response, $tenant, $learners];
        };

        [$small] = $measure(2);
        [$large, $response, $tenant, $learners] = $measure(15);

        $this->assertLessThan(20, $large, "/superadmin: {$large} Abfragen");
        $this->assertLessThanOrEqual(1, $large - $small, "/superadmin: {$large} Abfragen statt {$small} -- wächst mit den Lernenden (N+1?).");
        $this->assertSame($learners, (int) $response->viewData('activeByTenant')[$tenant->id]);
        $this->assertGreaterThanOrEqual($learners + 2, $response->viewData('activeLearners'));
        $this->assertFalse($response->viewData('inactiveTenants')->contains('id', $tenant->id));
    }
}
