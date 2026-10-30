<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\ExamBlueprint;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\Favorite;
use App\Models\Tenant;
use App\Models\User;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review B29: "Als Favorit speichern" sendete bisher ein verstecktes
 * Formular ab (document.getElementById('favorite-form').submit()), das die
 * gesamte Prüfungsseite neu lud -- eine bereits angeklickte, aber noch
 * nicht abgeschickte Antwort ging dadurch verloren. Der Favoriten-Toggle
 * läuft jetzt per fetch() ohne Seitennavigation, die Antwortauswahl bleibt
 * also unverändert im DOM erhalten.
 */
class ExamFavoriteToggleTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdRuleSetIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenants();

        $this->onAdmin(function () {
            foreach ($this->createdRuleSetIds as $id) {
                ExamRuleSet::where('id', $id)->delete();
            }
        });

        $this->cleanUpCreatedUsers();

        parent::tearDown();
    }

    public function test_the_exam_question_page_no_longer_uses_a_page_navigating_favorite_form(): void
    {
        [$tenant, $learner, $course] = $this->setUpVerifiedExamCourse();

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        $session = $this->asTenant($tenant, fn () => ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail());

        $response = $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}");

        $response->assertOk();
        // Die alte, seitennavigierende Variante gibt es nicht mehr.
        $response->assertDontSee("getElementById('favorite-form')", false);
        $response->assertDontSee('id="favorite-form"', false);
        // Die neue Variante: ein Button mit question-id/favorite-Status, der
        // nur noch per fetch() (ohne Formular-Submit) umschaltet.
        // Sicherheitsreview M9: der Handler ist inzwischen ein Alpine-@click
        // statt eines onclick-Attributs (siehe SecurityHeaders, CSP ohne
        // unsafe-inline für script-src) -- "this" wird dabei zu Alpines
        // Magic-Property $el, verhält sich aber identisch.
        $response->assertSee('toggleFavorite($el)', false);
        $response->assertSee('data-question-id=', false);
    }

    public function test_favoriting_a_question_during_an_exam_does_not_touch_the_running_session(): void
    {
        [$tenant, $learner, $course] = $this->setUpVerifiedExamCourse();

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        [$session, $question] = $this->asTenant($tenant, function () use ($tenant, $learner) {
            $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();
            $question = $session->questions()->orderBy('position')->firstOrFail();

            return [$session, $question];
        });

        // Simuliert exakt das, was der fetch()-Aufruf aus toggleFavorite()
        // an den Server schickt.
        $response = $this->actingAsInTenant($learner, $tenant)
            ->postJson("/favorites/{$question->question_id}", ['context' => 'exam', 'course_id' => $course->id]);

        // Bug-Review T2: back() lieferte hier bis dahin unabhängig vom
        // Accept-Header einen Redirect -- bei einem 301/302 ersetzt
        // fetch() beim automatischen Folgen des Redirects die Methode aber
        // nur für POST durch GET (WHATWG Fetch "HTTP-redirect fetch"); das
        // Redirect-Ziel (die Prüfungsseite selbst) hat ohnehin keine
        // DELETE-Route (siehe das DELETE-Pendant unten in diesem Bereich),
        // und selbst für den POST-Fall hier ist response.ok in
        // toggleFavorite() der einzige Signalweg -- ein zusätzlicher
        // Redirect ist für den fetch()-Aufruf witzlos. FavoriteController
        // liefert jetzt stattdessen 204 No Content, wenn der Request (wie
        // dieser) application/json akzeptiert.
        $response->assertNoContent();
        $this->asTenant($tenant, function () use ($tenant, $learner, $question) {
            $this->assertDatabaseHas('favorite', [
                'tenant_id' => $tenant->id,
                'user_id' => $learner->id,
                'question_id' => $question->question_id,
                'context' => Favorite::CONTEXT_EXAM,
            ]);
        });

        $session = $this->asTenant($tenant, fn () => ExamSession::find($session->id));
        $this->assertSame('running', $session->status);
    }

    /**
     * Bug-Review (Hoch): der Favoriten-Button bei einer offenen Frage
     * (question_type=open_text) rief toggleFavorite() bisher OHNE das
     * $el-Argument auf, anders als die beiden anderen Fragetypen oben in
     * dieser Datei. toggleFavorite(btn) griff dadurch sofort auf
     * btn.dataset zu, wo btn undefined war -- ein TypeError, bevor der
     * fetch()-Aufruf überhaupt feuerte. Der Button hatte zusätzlich weder
     * data-favorite noch data-question-id gesetzt. Da dieses Projekt keine
     * JS-Testinfrastruktur hat, pinnt dieser Test die (jetzt für alle drei
     * Fragetypen identische) Quelltext-Struktur fest statt das Verhalten im
     * Browser zu simulieren.
     */
    public function test_the_open_text_favorite_button_passes_el_and_carries_the_same_data_attributes_as_the_other_question_types(): void
    {
        $source = file_get_contents(resource_path('views/exam/question.blade.php'));

        $this->assertSame(
            3,
            substr_count($source, '@click="toggleFavorite($el)"'),
            'Alle drei Fragetypen (multi_choice, open_text, single_choice) müssen $el übergeben.'
        );
        $this->assertStringNotContainsString('@click="toggleFavorite()"', $source);
        $this->assertSame(3, substr_count($source, 'data-question-id="{{ $current->question_id }}"'));
        $this->assertSame(3, substr_count($source, "data-favorite=\"{{ \$isFavorite ? '1' : '0' }}\""));
    }

    /** @return array{0: Tenant, 1: User, 2: CourseDefinition} */
    private function setUpVerifiedExamCourse(): array
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('ADD-UBI-TO-SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('UBI');
        $reviewer = $this->createTenantUser($tenant, 'admin');

        $this->onAdmin(function () use ($course, $module, $reviewer) {
            $ruleSet = ExamRuleSet::create([
                'course_id' => $course->id,
                'version' => 'test-'.uniqid(),
                'time_limit_seconds' => 3600,
                'passing_rule' => ['min_correct_ratio' => 0.75],
                'status' => 'published',
                'verified_by' => $reviewer->id,
                'verified_at' => now(),
            ]);

            ExamBlueprint::create([
                'rule_set_id' => $ruleSet->id,
                'module_id' => $module->id,
                'question_count' => 3,
            ]);

            $this->createdRuleSetIds[] = $ruleSet->id;
        });

        return [$tenant, $learner, $course];
    }
}
