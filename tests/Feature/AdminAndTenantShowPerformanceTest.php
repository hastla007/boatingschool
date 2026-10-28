<?php

namespace Tests\Feature;

use App\Models\Progress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review Betrieb B6: sowohl /admin (AdminDashboardController) als auch
 * /superadmin/tenants/{id} (Superadmin\TenantController::show()) riefen
 * CourseProgressService::overallPercent() einmal PRO Freischaltung auf --
 * jeder Aufruf lud dabei frisch die Modul-/Fragen-Relation des Kurses UND
 * fragte die Progress-Tabelle erneut ab (dasselbe N+1-Muster, das
 * Bug-Review P1 bereits für Superadmin\UserController::show() behoben
 * hatte). Bei 500 Lernenden wurden /admin und /superadmin/tenants/{id}
 * dadurch mit mehreren hundert zusätzlichen Queries spürbar langsam; die
 * unpaginierten Listen (Nutzer, Kursergebnisse) auf /superadmin/tenants/{id}
 * ließen zusätzlich das gerenderte HTML auf mehrere hundert KB wachsen.
 *
 * Analog zu P1 wird Progress hier für die betroffenen Nutzer einmal
 * komplett geladen und die Prozentzahl im Speicher berechnet; beide Listen
 * auf /superadmin/tenants/{id} sind jetzt serverseitig paginiert.
 */
class AdminAndTenantShowPerformanceTest extends TestCase
{
    use InteractsWithTenants;

    /**
     * Bug-Review Betrieb B6: ein absoluter Query-Schwellwert bei einer
     * kleinen, in einem Test praktikablen Lernerzahl (z. B. 8) kann das
     * N+1-Muster nicht zuverlässig fangen -- der konstante Sockel an
     * Queries (Tenant/Branding/Teilnehmer/Coupons/...) überdeckt bei
     * kleinem N den linearen Mehrverbrauch. Stattdessen wird hier die
     * Query-Zahl für zwei verschieden große Lernergruppen verglichen: die
     * DIFFERENZ darf mit mehr Lernenden nicht weiter wachsen -- mit dem
     * alten N+1 wuchs sie 1:1 mit der Lernerzahl (12 zusätzliche Lerner =
     * 12 zusätzliche Queries), nach dem Fix bleibt sie konstant.
     */
    private function queryCountForAdminDashboard(int $learnerCount, string $courseCode = 'SRC'): int
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse($courseCode);

        foreach (range(1, $learnerCount) as $i) {
            $learner = $this->createTenantUser($tenant, 'learner');
            $this->grantEntitlement($tenant, $learner, $course);
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $response = $this->actingAsInTenant($owner, $tenant)->get('/admin');
        $response->assertOk();

        return $queryCount;
    }

    private function queryCountForTenantShow(int $learnerCount, string $courseCode = 'SRC'): int
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $course = $this->existingCourse($courseCode);

        foreach (range(1, $learnerCount) as $i) {
            $learner = $this->createTenantUser($tenant, 'learner');
            $this->grantEntitlement($tenant, $learner, $course);
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $response = $this->actingAs($superadmin)->get("/superadmin/tenants/{$tenant->id}");
        $response->assertOk();

        return $queryCount;
    }

    public function test_admin_dashboard_query_count_does_not_scale_with_learner_count(): void
    {
        $small = $this->queryCountForAdminDashboard(3);
        $large = $this->queryCountForAdminDashboard(15);

        $this->assertLessThanOrEqual(3, $large - $small, "Admin-Dashboard braucht bei 15 Lernenden {$large} Queries statt {$small} bei 3 -- die Differenz wächst mit mehr Lernenden, vermutlich wieder ein N+1.");
    }

    public function test_tenant_show_query_count_does_not_scale_with_learner_count(): void
    {
        $small = $this->queryCountForTenantShow(3);
        $large = $this->queryCountForTenantShow(15);

        $this->assertLessThanOrEqual(3, $large - $small, "/superadmin/tenants/{id} braucht bei 15 Lernenden {$large} Queries statt {$small} bei 3 -- die Differenz wächst mit mehr Lernenden, vermutlich wieder ein N+1.");
    }

    public function test_tenant_show_displays_the_correct_progress_percentage_per_learner(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $course = $this->existingCourse('SRC');
        $module = $this->existingModule('SRC');
        $questionIds = $module->questions()->pluck('content_question.id');
        $this->assertGreaterThanOrEqual(2, $questionIds->count());

        // Bug-Review Testisolation: createTenantUser() vergibt ohne eigenen
        // display_name standardmäßig "Test Learner" für JEDEN Lerner --
        // Str::between() könnte zwei gleich benannte Lerner nicht
        // unterscheiden, deshalb hier zwei eindeutige Namen.
        $learnerWithProgress = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Lernerin Mit Fortschritt']);
        $learnerWithoutProgress = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Lerner Ohne Fortschritt']);
        $this->grantEntitlement($tenant, $learnerWithProgress, $course);
        $this->grantEntitlement($tenant, $learnerWithoutProgress, $course);

        $this->onAdmin(fn () => Progress::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learnerWithProgress->id,
            'question_id' => $questionIds->first(),
            'learning_state' => 'gefestigt',
        ]));

        $response = $this->actingAs($superadmin)->get("/superadmin/tenants/{$tenant->id}");
        $response->assertOk();

        $expectedPercent = (int) round(1 / $questionIds->count() * 100);

        $resultsSection = Str::between($response->getContent(), 'Kursergebnisse', 'Coupon-Codes');
        $rowWithProgress = Str::between($resultsSection, $learnerWithProgress->name, '</tr>');
        $rowWithoutProgress = Str::between($resultsSection, $learnerWithoutProgress->name, '</tr>');
        $this->assertStringContainsString($expectedPercent.'%', $rowWithProgress);
        $this->assertStringContainsString('0%', $rowWithoutProgress);
        $this->assertStringNotContainsString($expectedPercent.'%', $rowWithoutProgress);
    }

    public function test_admin_dashboard_learning_phase_distribution_is_computed_per_learner_not_merged(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        // Bug-Review Testisolation: FKN ist ein Vollkurs mit genau einem
        // Modul (siehe CourseSeeder) -- die Prozentzahl im Dashboard
        // bezieht sich auf ALLE Module des Kurses zusammen, ein
        // Kombikurs mit mehreren Modulen würde den unten berechneten
        // $masteredCount-Anteil sonst verwässern.
        $course = $this->existingCourse('FKN');
        $module = $this->existingModule('FKN');
        $questionIds = $module->questions()->pluck('content_question.id');
        $this->assertGreaterThanOrEqual(10, $questionIds->count());

        $learnerWithProgress = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Lernerin Mit Fortschritt']);
        $learnerWithoutProgress = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Lerner Ohne Fortschritt']);
        $this->grantEntitlement($tenant, $learnerWithProgress, $course);
        $this->grantEntitlement($tenant, $learnerWithoutProgress, $course);

        // Genug Fragen gefestigt, um sicher über die 20%-Schwelle zu
        // kommen -- so landet dieser Lerner in einem anderen Balken als
        // der Lerner ohne jeden Fortschritt (0%, immer "0-20%"), und ein
        // versehentliches Vermischen beider Lerner im selben Eimer fiele auf.
        $masteredCount = (int) ceil($questionIds->count() * 0.25);
        foreach ($questionIds->take($masteredCount) as $questionId) {
            $this->onAdmin(fn () => Progress::create([
                'tenant_id' => $tenant->id,
                'user_id' => $learnerWithProgress->id,
                'question_id' => $questionId,
                'learning_state' => 'gefestigt',
            ]));
        }
        $expectedPercent = (int) round($masteredCount / $questionIds->count() * 100);
        $this->assertGreaterThan(20, $expectedPercent);
        $expectedBucket = match (true) {
            $expectedPercent <= 40 => '21–40%',
            $expectedPercent <= 60 => '41–60%',
            $expectedPercent <= 80 => '61–80%',
            default => '81–100%',
        };

        $response = $this->actingAsInTenant($owner, $tenant)->get('/admin');
        $response->assertOk();

        $this->assertSame(2, $response->viewData('learningPhaseLearnerCount'));
        $buckets = collect($response->viewData('progressDistribution'))->keyBy('label');
        $this->assertSame(1, $buckets['0–20%']['count'], 'Der Lerner ohne Fortschritt muss im 0-20%-Balken landen.');
        $this->assertSame(1, $buckets[$expectedBucket]['count'], "Der Lerner mit {$expectedPercent}% Fortschritt muss im {$expectedBucket}-Balken landen.");
    }
}
