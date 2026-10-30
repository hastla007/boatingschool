<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Kachel "Sonstiges" zeigt "keine Fragen"): FKN/SKN-Fragen haben
 * kein smartmodus_kategorie/topic, landen deshalb in der Themen-Kachel-
 * Aufschlüsselung (CourseProgressService::moduleKategorieBreakdown()) unter
 * dem Label "Sonstiges" -- der Klick auf diese Kachel (?mode=topic&topic=
 * Sonstiges) filterte in SmarttrainerService::nextQuestion() aber gegen
 * questionTopic() === 'Sonstiges', was für diese Fragen nie zutraf
 * (questionTopic() liefert null statt "Sonstiges"), sodass ALLE Fragen
 * herausfielen.
 */
class SonstigesTopicTileMatchesClickThroughTest extends TestCase
{
    use InteractsWithTenants;

    public function test_clicking_the_sonstiges_tile_for_fkn_actually_returns_a_question(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('FKN');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)
            ->get(route('learning.show', $course).'?mode=topic&topic='.urlencode('Sonstiges'));

        $response->assertOk();
        $response->assertDontSee('Keine Fragen in diesem Modus verfügbar');
    }
}
