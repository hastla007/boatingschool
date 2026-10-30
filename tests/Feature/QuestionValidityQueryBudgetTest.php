<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (BB16-Folgefehler): die Gültigkeitsprüfung je Frage
 * (ContentQuestion::isCurrentlyValid()) lud pro Frage eine Revision nach --
 * für einen Lernenden im Kombikurs SBF See & Binnen ergab das auf dem
 * Dashboard rund 500, auf der Kursseite fast 1.000 und in der Lernübersicht
 * über 1.400 Abfragen. Gefiltert wird jetzt mit einer Abfrage pro
 * Collection (ContentQuestion::filterCurrentlyValid()).
 */
class QuestionValidityQueryBudgetTest extends TestCase
{
    use InteractsWithTenants;

    public function test_learner_pages_of_the_largest_course_stay_within_a_query_budget(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE-BIN');
        $this->grantEntitlement($tenant, $learner, $course);

        foreach (['/dashboard', "/courses/{$course->id}", "/courses/{$course->id}/learn/overview", "/courses/{$course->id}/progress"] as $url) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAsInTenant($learner, $tenant)->get($url)->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            fwrite(STDERR, "{$url}: {$count}\n");
            $this->assertLessThan(100, $count, "{$url} braucht {$count} Abfragen");
        }
    }
}
