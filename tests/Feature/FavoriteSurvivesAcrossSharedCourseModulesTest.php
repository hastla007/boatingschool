<?php

namespace Tests\Feature;

use App\Models\Favorite;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Favoriten gehen beim Wechsel zwischen zwei Kursen verloren):
 * favorite trägt bewusst KEIN course_id -- dieselbe Frage kann über ein
 * gemeinsam genutztes Modul zu mehreren Kursen gehören (z. B. das SRC-Modul
 * sowohl in SRC als auch im Kombikurs SRC-UBI), und hat dafür genau EINEN
 * geteilten Favorite-Datensatz. Jeder Kurs hat aber seine eigenen,
 * course_id-gescopten FavoriteCollections.
 *
 * FavoriteCollectionService::syncQuestion() löschte den Favoriten bisher
 * komplett, sobald eine Frage in NUR EINEM der beiden Kurse aus allen
 * Collections entfernt wurde ("In Collections einsortieren" ohne Haken
 * abgeschickt) -- auch wenn sie im ANDEREN Kurs weiterhin einsortiert war.
 * Der Favorit verschwand dadurch aus BEIDEN Kursen, nicht nur aus dem, in
 * dem synchronisiert wurde.
 */
class FavoriteSurvivesAcrossSharedCourseModulesTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_syncing_a_shared_questions_collections_in_one_course_does_not_delete_the_favorite_from_the_other_course(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $src = $this->existingCourse('SRC');
        $srcUbi = $this->existingCourse('SRC-UBI');
        $this->grantEntitlement($tenant, $learner, $src);
        $this->grantEntitlement($tenant, $learner, $srcUbi);

        $module = $this->existingModule('SRC');
        [$question] = $this->anyPublishedQuestionIn($module);

        // Favorisieren über den SRC-Kurs -- landet automatisch in SRCs
        // Default-Collection (FavoriteCollectionService::ensureInDefault()).
        $this->actingAsInTenant($learner, $tenant)->post(route('favorites.store', $question), [
            'context' => 'smart_learning',
            'course_id' => $src->id,
        ])->assertRedirect();

        $this->onAdmin(function () use ($tenant, $learner, $question) {
            $this->assertTrue(Favorite::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
                ->where('question_id', $question->id)->where('context', 'smart_learning')->exists());
        });

        // Im ANDEREN Kurs (SRC-UBI) wird dieselbe, geteilte Frage über den
        // Collections-Dialog synchronisiert -- ohne eine Collection
        // auszuwählen (so, wie es passiert, wenn der Lerner sie dort aus
        // allen Collections entfernt bzw. den Dialog ohne Haken abschickt).
        $this->actingAsInTenant($learner, $tenant)->put(route('favorite-collections.sync', [$srcUbi, $question]), [
            'context' => 'smart_learning',
            'collection_ids' => [],
        ])->assertRedirect();

        // Der Favorit bleibt erhalten, weil er in SRCs Default-Collection
        // weiterhin einsortiert ist -- nur weil in SRC-UBI nichts
        // ausgewählt wurde, darf er nicht global verschwinden.
        $this->onAdmin(function () use ($tenant, $learner, $question) {
            $this->assertTrue(Favorite::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
                ->where('question_id', $question->id)->where('context', 'smart_learning')->exists());
        });

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('favorites.smart-learning', $src));
        $response->assertOk();
        $response->assertSee($question->publishedRevision()->question_text);
    }
}
