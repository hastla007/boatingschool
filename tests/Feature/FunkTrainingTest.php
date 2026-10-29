<?php

namespace Tests\Feature;

use App\Models\RadioTrainingProgress;
use App\Support\FunkPraxis;
use App\Support\FunkTraining;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Funk-Training für SRC und UBI: Praxis-Prüfungssimulation (regelbasierte
 * Auswertung, siehe FunkPraxis) und mündliche Prüfung (Vokabeln,
 * Seefunktexte).
 */
class FunkTrainingTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_every_model_solution_passes_all_of_its_checks_and_an_empty_answer_passes_none(): void
    {
        foreach (FunkPraxis::CATALOGS as $catalog) {
            $tasks = FunkPraxis::tasks($catalog);
            $this->assertNotEmpty($tasks);

            foreach ($tasks as $task) {
                $solution = array_column($task['fields'], 'solution', 'name');
                $result = FunkPraxis::evaluate($task, $solution);
                $this->assertSame($result['total'], $result['passed'], "{$task['key']}: Musterlösung erfüllt nicht alle Prüfpunkte.");
                $this->assertSame(0, FunkPraxis::evaluate($task, [])['passed'], "{$task['key']}: leere Eingabe erfüllt Prüfpunkte.");
            }
        }

        $this->assertCount(42, FunkPraxis::tasks('SRC'));
        $this->assertCount(30, FunkPraxis::tasks('UBI'));
    }

    public function test_evaluation_tells_a_distress_message_from_an_urgency_message(): void
    {
        $distress = collect(FunkPraxis::tasks('SRC'))->firstWhere('kind', 'distress');
        $urgency = collect(FunkPraxis::tasks('SRC'))->firstWhere('kind', 'urgency');
        $urgencySolution = array_column($urgency['fields'], 'solution', 'name');

        $result = FunkPraxis::evaluate($distress, $urgencySolution);

        $this->assertFalse($result['fields']['rank'][0]['ok'], 'Dringlichkeitsverkehr darf bei einer Notlage nicht als richtig gelten.');
        $this->assertFalse(collect($result['fields']['message'])->firstWhere('label', 'MAYDAY dreimal')['ok']);
    }

    public function test_call_signs_and_digits_are_recognised_when_spelled(): void
    {
        $task = collect(FunkPraxis::tasks('SRC'))->firstWhere('kind', 'distress');
        $spelled = str_replace(['THIS IS', 'OVER'], ['this is', 'over'], array_column($task['fields'], 'solution', 'name')['message']);
        $result = FunkPraxis::evaluate($task, ['message' => mb_strtolower($spelled)]);

        $this->assertTrue(collect($result['fields']['message'])->firstWhere('label', 'Rufzeichen')['ok']);
    }

    public function test_only_radio_courses_show_the_funk_tiles_and_pages(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $combo = $this->existingCourse('SRC-UBI');
        $sbf = $this->existingCourse('SBF-SEE');
        $ubi = $this->existingCourse('UBI');
        foreach ([$combo, $sbf, $ubi] as $course) {
            $this->grantEntitlement($tenant, $learner, $course);
        }

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$combo->id}")
            ->assertOk()->assertSee('Praxis-Prüfungssimulation')->assertSee('Mündliche Prüfung');
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$ubi->id}")
            ->assertOk()->assertSee('Praxis-Prüfungssimulation')->assertDontSee('Seefunktexte und Vokabeln');
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$sbf->id}")
            ->assertOk()->assertDontSee('Praxis-Prüfungssimulation');

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$sbf->id}/funk/praxis")->assertNotFound();
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$ubi->id}/funk/praxis/SRC/1")->assertNotFound();
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$combo->id}/funk/praxis/SRC/999")->assertNotFound();
    }

    public function test_funk_pages_require_an_entitlement(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $combo = $this->existingCourse('SRC-UBI');

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$combo->id}/funk/praxis")->assertForbidden();
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$combo->id}/funk/muendlich")->assertForbidden();
    }

    public function test_evaluating_a_task_stores_the_answers_and_shows_checks_and_model_solution(): void
    {
        [$tenant, $learner, $combo] = $this->learnerInCombo();
        $task = FunkPraxis::tasks('UBI')[1];
        $solution = array_column($task['fields'], 'solution', 'name');

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$combo->id}/funk/praxis?teil=UBI")->assertOk()->assertSee('0 von 30 Aufgaben erledigt');
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$combo->id}/funk/praxis/UBI/1")->assertOk()->assertSee($task['situation_de'])->assertDontSee('Prüfpunkten erfüllt');

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$combo->id}/funk/praxis/UBI/1", ['answers' => ['rank' => '', 'message' => '']])
            ->assertSessionHasErrors('answers');

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$combo->id}/funk/praxis/UBI/1", ['answers' => $solution])
            ->assertRedirect("/courses/{$combo->id}/funk/praxis/UBI/1?auswertung=1")->assertSessionHasNoErrors();
        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$combo->id}/funk/praxis/UBI/1?auswertung=1");

        $total = FunkPraxis::evaluate($task, $solution)['total'];
        $response->assertOk()->assertSee('Musterlösung')->assertSee("{$total} von {$total} Prüfpunkten erfüllt");

        $stored = $this->asTenant($tenant, fn () => RadioTrainingProgress::where('user_id', $learner->id)->where('item_key', 'UBI-01')->firstOrFail());
        $this->assertSame($solution['message'], $stored->answers['message']);
        $this->assertFalse($stored->completed);
    }

    public function test_marking_a_task_as_done_toggles_and_counts_on_the_overview(): void
    {
        [$tenant, $learner, $combo] = $this->learnerInCombo();

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$combo->id}/funk/praxis/SRC/3/erledigt")->assertRedirect();
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$combo->id}/funk/praxis")->assertSee('1 von 42 Aufgaben erledigt');

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$combo->id}/funk/praxis/SRC/3/erledigt")->assertRedirect();
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$combo->id}/funk/praxis")->assertSee('0 von 42 Aufgaben erledigt');
    }

    public function test_a_vocabulary_is_learned_after_three_correct_answers_in_a_row(): void
    {
        [$tenant, $learner, $combo] = $this->learnerInCombo();
        $key = collect(FunkTraining::vocabulary('SRC'))->flatten(1)->first()['key'];
        $answer = fn (bool $known) => $this->actingAsInTenant($learner, $tenant)
            ->postJson("/courses/{$combo->id}/funk/muendlich/SRC/vokabeln", ['key' => $key, 'known' => $known]);

        $answer(true)->assertJson(['streak' => 1, 'learned' => false]);
        $answer(false)->assertJson(['streak' => 0, 'learned' => false]);
        $answer(true);
        $answer(true);
        $answer(true)->assertJson(['streak' => 3, 'learned' => true, 'learnedCount' => 1]);

        $this->actingAsInTenant($learner, $tenant)->postJson("/courses/{$combo->id}/funk/muendlich/SRC/vokabeln", ['key' => 'VOC-SRC-unbekannt', 'known' => true])->assertNotFound();
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$combo->id}/funk/muendlich?teil=SRC&modul=vokabeln&ansicht=uebersicht")
            ->assertOk()->assertSee('1 / '.collect(FunkTraining::vocabulary('SRC'))->flatten(1)->count().' Vokabeln gelernt');
    }

    public function test_oral_exam_explains_the_missing_parts(): void
    {
        [$tenant, $learner, $combo] = $this->learnerInCombo();

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$combo->id}/funk/muendlich")
            ->assertOk()->assertSee('Seefunktexte')->assertSee('werden hier ergänzt, sobald sie');
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$combo->id}/funk/muendlich?teil=UBI")
            ->assertOk()->assertSee('Die UBI-Prüfung hat keinen englischen Teil');
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$combo->id}/funk/muendlich/SRC/texte/1")->assertNotFound();
    }

    public function test_every_vocabulary_has_a_unique_key_and_german_term(): void
    {
        $entries = collect(FunkTraining::vocabulary('SRC'))->flatten(1);

        $this->assertGreaterThanOrEqual(380, $entries->count());
        $this->assertSame($entries->count(), $entries->pluck('key')->unique()->count(), 'Zwei Vokabeln teilen sich einen Fortschrittsschlüssel.');
        $this->assertSame($entries->count(), $entries->pluck('de')->unique()->count(), 'Ein deutscher Begriff steht doppelt in der Liste.');
        $this->assertContains("radio operator's certificate", $entries->pluck('en')->all());
    }

    public function test_word_match_ignores_case_and_punctuation(): void
    {
        $result = FunkTraining::wordMatch('Mayday, this is Kormoran.', 'MAYDAY this is kormoran');
        $this->assertSame(100, $result['percent']);

        $result = FunkTraining::wordMatch('I require immediate assistance', 'I require assistance');
        $this->assertSame(75, $result['percent']);
        $this->assertSame(['IMMEDIATE'], $result['missing']);
    }

    private function learnerInCombo(): array
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $combo = $this->existingCourse('SRC-UBI');
        $this->grantEntitlement($tenant, $learner, $combo);

        return [$tenant, $learner, $combo];
    }
}
