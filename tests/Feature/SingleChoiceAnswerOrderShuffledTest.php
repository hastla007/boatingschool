<?php

namespace Tests\Feature;

use App\Models\ContentQuestionRevision;
use App\Models\Favorite;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Antwortreihenfolge bei Einfachauswahl-Fragen immer identisch):
 * im Quellkatalog (zentrale_fragenbasis.xlsx) steht die richtige Antwort bei
 * 823 von 838 Einfachauswahl-Fragen an erster Stelle (correct_answer=A), und
 * ContentQuestionRevision::answers() behält diese sort_order unverändert bei
 * -- Lernseite, Prüfungssimulation und Video-Quiz zeigten die Antworten
 * dadurch de facto immer in derselben, verratenden Reihenfolge an.
 * ContentQuestionRevision::answersInDisplayOrderFor() mischt sie jetzt
 * deterministisch pro Nutzer-ID + Antwort-ID.
 */
class SingleChoiceAnswerOrderShuffledTest extends TestCase
{
    use InteractsWithTenants;

    private function realSingleChoiceRevisionWithCorrectAnswerFirst(): ContentQuestionRevision
    {
        $revision = $this->onAdmin(fn () => ContentQuestionRevision::where('question_type', 'single_choice')
            ->where('editorial_status', 'published')
            ->whereHas('answers', fn ($q) => $q->where('sort_order', 1)->where('is_correct', true))
            ->first());

        $this->assertNotNull($revision, 'Es muss mindestens eine veröffentlichte Einfachauswahl-Frage mit korrekter Antwort an sort_order=1 geben (823 von 838 im echten Katalog).');

        $revision->load('answers');
        $this->assertGreaterThanOrEqual(3, $revision->answers->count());

        return $revision;
    }

    public function test_display_order_is_a_stable_permutation_that_is_not_always_the_natural_sort_order(): void
    {
        $revision = $this->realSingleChoiceRevisionWithCorrectAnswerFirst();
        $naturalIds = $revision->answers->pluck('id')->all();

        $correctAnswerFirstCount = 0;
        $userIds = array_map(fn () => (string) Str::uuid(), range(1, 30));

        foreach ($userIds as $userId) {
            $shuffled = $revision->answersInDisplayOrderFor($userId);

            // Komplette Permutation: keine Antwort geht verloren, keine wird verdoppelt.
            $this->assertEqualsCanonicalizing($naturalIds, $shuffled->pluck('id')->all());

            // Stabil bei wiederholtem Aufruf für denselben Nutzer (gleiche Prüfung, Neuladen).
            $this->assertSame($shuffled->pluck('id')->all(), $revision->answersInDisplayOrderFor($userId)->pluck('id')->all());

            if ($shuffled->first()->is_correct) {
                $correctAnswerFirstCount++;
            }
        }

        // Bei echter Durchmischung landet die richtige Antwort nicht bei (annähernd)
        // allen 30 zufälligen Nutzern an erster Stelle -- das wäre genau der Bug.
        $this->assertLessThan(30, $correctAnswerFirstCount, 'Die richtige Antwort steht weiterhin für praktisch jeden Nutzer an erster Stelle.');
    }

    public function test_display_order_is_unchanged_without_a_user_id(): void
    {
        $revision = $this->realSingleChoiceRevisionWithCorrectAnswerFirst();

        $this->assertSame($revision->answers->pluck('id')->all(), $revision->answersInDisplayOrderFor(null)->pluck('id')->all());
    }

    /**
     * Treibt die Frage über die echte Route (statt isolierter Blade-Wiedergabe,
     * die an Middleware-gespeistem Shared-View-State wie $cspNonce/$errors
     * scheitert) -- der Favoriten-Modus wählt deterministisch die einzige
     * hinterlegte Favoriten-Frage des Nutzers aus (inRandomOrder() über genau
     * einen Kandidaten).
     */
    public function test_learning_page_renders_the_shuffled_order_instead_of_the_natural_one(): void
    {
        $course = $this->existingCourse('SBF-SEE');
        $courseQuestionIds = $this->onAdmin(fn () => $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id')->unique());

        $revision = $this->onAdmin(fn () => ContentQuestionRevision::where('question_type', 'single_choice')
            ->where('editorial_status', 'published')
            ->whereHas('answers', fn ($q) => $q->where('sort_order', 1)->where('is_correct', true))
            ->whereIn('question_id', $courseQuestionIds)
            ->first());
        $this->assertNotNull($revision, 'Es muss eine veröffentlichte SBF-SEE-Einfachauswahl-Frage mit korrekter Antwort an sort_order=1 geben.');
        $revision->load('answers');

        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner', ['id' => '11111111-1111-1111-1111-111111111111']);
        $this->grantEntitlement($tenant, $learner, $course);

        $expected = $revision->answersInDisplayOrderFor($learner->id)->pluck('id')->all();
        $this->assertNotSame($revision->answers->pluck('id')->all(), $expected, 'Testvoraussetzung: für diesen Nutzer muss sich die gemischte von der natürlichen Reihenfolge unterscheiden.');

        $this->onAdmin(fn () => Favorite::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'question_id' => $revision->question_id,
            'context' => Favorite::CONTEXT_SMART_LEARNING,
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)
            ->get(route('learning.show', $course).'?mode=favorites');

        $response->assertOk();
        preg_match_all('/name="answer_id" value="([0-9a-f-]{36})"/', $response->getContent(), $matches);
        $this->assertSame($expected, $matches[1], 'Die Lernseite muss die Antworten in der nutzerspezifisch gemischten Reihenfolge rendern.');
    }
}
