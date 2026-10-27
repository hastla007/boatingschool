<?php

namespace Tests\Feature;

use App\Models\Favorite;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review Oberfläche U2 (dasselbe Muster wie Bug-Review B29 in
 * exam/question.blade.php): "Als Favorit speichern" sendete im Smart-
 * Learning bisher ein verstecktes Formular ab
 * (document.getElementById('favorite-form').submit()), das die gesamte
 * Lernseite neu lud -- eine bereits angeklickte, aber noch nicht
 * abgeschickte Antwort (x-model="selected") ging dadurch verloren. Der
 * Favoriten-Toggle läuft jetzt wie bei der Prüfungssimulation per fetch()
 * ohne Seitennavigation, die Antwortauswahl bleibt also unverändert im DOM
 * erhalten.
 */
class LearningFavoriteToggleTest extends TestCase
{
    use InteractsWithTenants;

    public function test_the_learning_question_page_no_longer_uses_a_page_navigating_favorite_form(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('UBI');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/learn?mode=smarttrainer");

        $response->assertOk();
        // Die alte, seitennavigierende Variante gibt es nicht mehr.
        $response->assertDontSee("getElementById('favorite-form')", false);
        $response->assertDontSee('id="favorite-form"', false);
        // Die neue Variante: ein Button mit question-id/favorite-Status, der
        // nur noch per fetch() (ohne Formular-Submit) umschaltet.
        $response->assertSee('toggleFavorite($el)', false);
        $response->assertSee('data-question-id=', false);
    }

    public function test_favoriting_a_question_during_smart_learning_does_not_touch_the_in_progress_attempt(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('UBI');
        $this->grantEntitlement($tenant, $learner, $course);
        $module = $this->existingModule('UBI');
        [$question] = $this->anyPublishedQuestionIn($module);

        // Simuliert exakt das, was der fetch()-Aufruf aus toggleFavorite()
        // an den Server schickt -- ohne irgendeine Antwort abzuschicken.
        $response = $this->actingAsInTenant($learner, $tenant)
            ->postJson("/favorites/{$question->id}", ['context' => 'smart_learning', 'course_id' => $course->id]);

        // back() liefert unabhängig vom Accept-Header einen Redirect --
        // genau das, was fetch() im Browser transparent weiterverfolgt.
        $response->assertRedirect();
        $this->asTenant($tenant, function () use ($tenant, $learner, $question) {
            $this->assertDatabaseHas('favorite', [
                'tenant_id' => $tenant->id,
                'user_id' => $learner->id,
                'question_id' => $question->id,
                'context' => Favorite::CONTEXT_SMART_LEARNING,
            ]);
        });
    }

    /**
     * Da dieses Projekt keine JS-Testinfrastruktur hat, pinnt dieser Test
     * die Quelltext-Struktur fest statt das Verhalten im Browser zu
     * simulieren -- analog zu ExamFavoriteToggleTest. Alle sechs
     * Vorkommen (multi_choice beantwortet/unbeantwortet, open_text
     * beantwortet/unbeantwortet, single_choice beantwortet/unbeantwortet)
     * müssen $el übergeben und dieselben data-Attribute tragen.
     */
    public function test_every_favorite_button_passes_el_and_carries_the_same_data_attributes(): void
    {
        $source = file_get_contents(resource_path('views/learning/question.blade.php'));

        $this->assertSame(
            6,
            substr_count($source, '@click="toggleFavorite($el)"'),
            'Alle sechs Favoriten-Buttons müssen $el übergeben.'
        );
        $this->assertStringNotContainsString('@click="toggleFavorite()"', $source);
        $this->assertSame(6, substr_count($source, 'data-question-id="{{ $revision->question_id }}"'));
        $this->assertSame(6, substr_count($source, "data-favorite=\"{{ \$isFavorite ? '1' : '0' }}\""));
    }
}
