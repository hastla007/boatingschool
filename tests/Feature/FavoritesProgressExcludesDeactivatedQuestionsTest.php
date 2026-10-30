<?php

namespace Tests\Feature;

use App\Models\Favorite;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review BB2: Der Favoriten-Fortschrittszähler auf der Lernübersicht
 * ("X/Y gemeistert") zählte bisher jede favorisierte Frage im Nenner mit,
 * auch wenn sie im Superadmin deaktiviert wurde -- eine deaktivierte Frage
 * taucht im Smarttrainer nicht mehr auf (Bug-Review F7) und kann deshalb
 * nie "gefestigt" werden, der Zähler blieb also dauerhaft hinter dem
 * tatsächlich erreichbaren Fortschritt zurück.
 */
class FavoritesProgressExcludesDeactivatedQuestionsTest extends TestCase
{
    use InteractsWithTenants;

    public function test_overview_favorites_counter_excludes_a_deactivated_favorite_from_the_total(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        [$questionOne, $questionTwo] = $this->onAdmin(function () use ($course) {
            $course->loadMissing('modules.questions');

            return $course->modules->flatMap(fn ($m) => $m->questions)
                ->where('active', true)
                ->unique('id')
                ->take(2)
                ->values()
                ->all();
        });

        $this->onAdmin(function () use ($tenant, $learner, $questionOne, $questionTwo) {
            Favorite::create(['tenant_id' => $tenant->id, 'user_id' => $learner->id, 'question_id' => $questionOne->id, 'context' => Favorite::CONTEXT_SMART_LEARNING]);
            Favorite::create(['tenant_id' => $tenant->id, 'user_id' => $learner->id, 'question_id' => $questionTwo->id, 'context' => Favorite::CONTEXT_SMART_LEARNING]);
        });

        // Diese Fragen gehören zum echten, geteilten SRC-Kurs (kein
        // Testdaten-Duplikat) -- der Aktiv-Status wird deshalb zwingend
        // zurückgesetzt, damit andere Tests dieselben Fragen weiterhin
        // aktiv vorfinden.
        $this->onAdmin(fn () => $questionOne->update(['active' => false]));

        try {
            $response = $this->actingAsInTenant($learner, $tenant)->get(route('learning.overview', $course));
            $response->assertOk();
            $response->assertSee('0/1 gemeistert', false);
            $response->assertDontSee('0/2 gemeistert', false);
        } finally {
            $this->onAdmin(function () use ($tenant, $learner, $questionOne, $questionTwo) {
                $questionOne->update(['active' => true]);
                Favorite::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
                    ->whereIn('question_id', [$questionOne->id, $questionTwo->id])
                    ->delete();
            });
        }
    }
}
