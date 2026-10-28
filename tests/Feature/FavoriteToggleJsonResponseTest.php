<?php

namespace Tests\Feature;

use App\Models\Favorite;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review T2: der Favoriten-Button in der Prüfungssimulation
 * (exam/question.blade.php, Bug-Review B29) ruft favorites.store/destroy
 * per fetch() mit Accept: application/json auf, gerade damit die noch
 * nicht abgeschickte Antwortauswahl im Hauptformular nicht durch eine
 * Seitennavigation verloren geht. Beide Actions lieferten dafür bisher
 * IMMER back() (302), unabhängig vom Accept-Header. Bei einem 301/302
 * ersetzt fetch() beim automatischen Folgen des Redirects die Methode nur
 * für POST durch GET (WHATWG Fetch "HTTP-redirect fetch") -- bei DELETE
 * bleibt die Methode DELETE, und das Redirect-Ziel (die Prüfungsseite
 * selbst) hat keine DELETE-Route, der Folge-Request landete also auf 405.
 * response.ok in toggleFavorite() wurde dadurch nie true, der Button
 * schaltete nie um.
 */
class FavoriteToggleJsonResponseTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_storing_a_favorite_via_fetch_gets_a_plain_204_instead_of_a_redirect(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);
        $module = $this->existingModule('SRC');
        [$question] = $this->anyPublishedQuestionIn($module);

        // Genau der Aufruf, den toggleFavorite() per fetch() macht:
        // Accept: application/json, kein Redirect-Follow-fähiger
        // Browser-Formular-Submit.
        $response = $this->actingAsInTenant($learner, $tenant)
            ->postJson("/favorites/{$question->id}", ['context' => 'exam', 'course_id' => $course->id]);

        $response->assertNoContent();

        $this->asTenant($tenant, function () use ($tenant, $learner, $question) {
            $this->assertDatabaseHas('favorite', [
                'tenant_id' => $tenant->id,
                'user_id' => $learner->id,
                'question_id' => $question->id,
                'context' => Favorite::CONTEXT_EXAM,
            ]);
        });
    }

    public function test_removing_a_favorite_via_fetch_with_the_delete_method_gets_a_plain_204_instead_of_a_redirect(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);
        $module = $this->existingModule('SRC');
        [$question] = $this->anyPublishedQuestionIn($module);

        $this->asTenant($tenant, function () use ($tenant, $learner, $question) {
            Favorite::create([
                'tenant_id' => $tenant->id,
                'user_id' => $learner->id,
                'question_id' => $question->id,
                'context' => Favorite::CONTEXT_EXAM,
            ]);
        });

        // toggleFavorite() ruft dafür fetch() mit method: 'DELETE' auf --
        // eine erneute 302-Redirect-Antwort würde der Browser mit einem
        // zweiten DELETE-Request auf das Redirect-Ziel folgen, das dafür
        // keine Route kennt (405).
        $response = $this->actingAsInTenant($learner, $tenant)
            ->deleteJson("/favorites/{$question->id}", ['context' => 'exam']);

        $response->assertNoContent();

        $this->asTenant($tenant, function () use ($tenant, $learner, $question) {
            $this->assertDatabaseMissing('favorite', [
                'tenant_id' => $tenant->id,
                'user_id' => $learner->id,
                'question_id' => $question->id,
                'context' => Favorite::CONTEXT_EXAM,
            ]);
        });
    }

    public function test_a_plain_form_submit_without_the_json_accept_header_still_gets_a_redirect(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);
        $module = $this->existingModule('SRC');
        [$question] = $this->anyPublishedQuestionIn($module);

        // learning/_favorite-card.blade.php sendet weiterhin ein echtes
        // Formular -- diese Navigation (back()) muss unverändert bleiben.
        $response = $this->actingAsInTenant($learner, $tenant)
            ->post("/favorites/{$question->id}", ['context' => 'smart_learning', 'course_id' => $course->id]);

        $response->assertRedirect();
    }
}
