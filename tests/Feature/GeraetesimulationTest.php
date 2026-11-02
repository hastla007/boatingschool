<?php

namespace Tests\Feature;

use App\Models\RadioTrainingProgress;
use App\Support\FunkPraxis;
use App\Support\Geraetesimulation;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Gerätesimulation Funk, Stufe 1 (IC-M505): Sichtbarkeit in der Beta,
 * Serverbewertung gesprochener Funksprüche und Lernstand je Lektion. Die
 * Gerätelogik selbst testet resources/js/funkgeraet/tests (npm test).
 */
class GeraetesimulationTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    private function superadminLearner(): array
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner', ['is_superadmin' => true]);
        $course = $this->existingCourse('SRC-UBI');
        $this->grantEntitlement($tenant, $user, $course);

        return [$tenant, $user, $course];
    }

    public function test_the_beta_is_visible_to_superadmins_only(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC-UBI');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}")->assertOk()->assertDontSee('Gerätesimulation');
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/funk/geraetesimulation")->assertNotFound();

        [$adminTenant, $admin] = $this->superadminLearner();
        $this->actingAsInTenant($admin, $adminTenant)->get("/courses/{$course->id}")->assertOk()->assertSee('Gerätesimulation');
        $this->actingAsInTenant($admin, $adminTenant)->get("/courses/{$course->id}/funk/geraetesimulation")
            ->assertOk()->assertSee('data-geraetesimulation-config', false)->assertSee('Icom IC-M505');

        config(['funk.geraetesimulation' => 'all']);
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/funk/geraetesimulation")->assertOk();
    }

    public function test_courses_without_maritime_radio_have_no_device_simulation(): void
    {
        config(['funk.geraetesimulation' => 'all']);
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $ubi = $this->existingCourse('UBI');
        $this->grantEntitlement($tenant, $learner, $ubi);

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$ubi->id}/funk/geraetesimulation")->assertNotFound();
        $this->assertFalse(Geraetesimulation::availableFor($learner, $this->existingCourse('SBF-SEE')));
    }

    public function test_the_browser_gets_no_speech_checks(): void
    {
        foreach (Geraetesimulation::lessonsForBrowser() as $lesson) {
            foreach ($lesson['steps'] as $step) {
                $this->assertIsBool($step['speech']);
            }
        }
        $this->assertCount(8, Geraetesimulation::data()['lessons']);
    }

    public function test_a_spoken_distress_message_is_scored_with_the_praxis_rules(): void
    {
        [$tenant, $admin, $course] = $this->superadminLearner();
        $lesson = Geraetesimulation::lesson('notmeldung-sprechen');
        $step = collect($lesson['steps'])->search(fn ($s) => isset($s['speech']));
        $task = FunkPraxis::scenario('SRC', $lesson['steps'][$step]['speech']['scenario']);
        $solution = collect($task['fields'])->firstWhere('name', 'message')['solution'];

        $url = "/courses/{$course->id}/funk/geraetesimulation/bewerten";
        $this->actingAsInTenant($admin, $tenant)->postJson($url, ['lesson' => 'notmeldung-sprechen', 'step' => $step, 'text' => mb_strtolower($solution)])
            ->assertOk()->assertJson(['passed' => true]);

        $failed = $this->actingAsInTenant($admin, $tenant)->postJson($url, ['lesson' => 'notmeldung-sprechen', 'step' => $step, 'text' => 'Pan pan, pan pan, pan pan, this is Kormoran'])
            ->assertOk()->assertJson(['passed' => false]);
        $this->assertFalse(collect($failed->json('checks'))->firstWhere('label', 'MAYDAY dreimal')['ok']);

        // Schritte ohne Funkspruch und unbekannte Lektionen werden abgewiesen.
        $silent = collect(Geraetesimulation::lesson('geraet-bedienen')['steps'])->search(fn ($s) => ! isset($s['speech']));
        $this->actingAsInTenant($admin, $tenant)->postJson($url, ['lesson' => 'geraet-bedienen', 'step' => $silent, 'text' => 'x'])->assertStatus(422);
        $this->actingAsInTenant($admin, $tenant)->postJson($url, ['lesson' => 'gibt-es-nicht', 'step' => 0, 'text' => 'x'])->assertStatus(422);
    }

    public function test_progress_keeps_the_best_level(): void
    {
        [$tenant, $admin, $course] = $this->superadminLearner();
        $url = "/courses/{$course->id}/funk/geraetesimulation/fortschritt";

        $this->actingAsInTenant($admin, $tenant)->postJson($url, ['lesson' => 'notalarm-senden', 'level' => 3])->assertOk()->assertJson(['level' => 3]);
        $this->actingAsInTenant($admin, $tenant)->postJson($url, ['lesson' => 'notalarm-senden', 'level' => 1])->assertOk()->assertJson(['level' => 3]);
        $this->actingAsInTenant($admin, $tenant)->postJson($url, ['lesson' => 'notalarm-senden', 'level' => 4])->assertStatus(422);

        $row = $this->onAdmin(fn () => RadioTrainingProgress::where('user_id', $admin->id)->where('item_key', 'ic-m505:notalarm-senden')->sole());
        $this->assertSame(RadioTrainingProgress::TYPE_DEVICE, $row->item_type);
        $this->assertTrue($row->completed);

        $this->actingAsInTenant($admin, $tenant)->get("/courses/{$course->id}/funk/geraetesimulation")
            ->assertOk()->assertSee('"notalarm-senden":3', false);
    }

    public function test_the_progress_table_accepts_the_device_type(): void
    {
        $definition = $this->onAdmin(fn () => DB::selectOne("select pg_get_constraintdef(oid) as def from pg_constraint where conrelid = 'radio_training_progress'::regclass and contype = 'c' and pg_get_constraintdef(oid) like '%item_type%'"));

        $this->assertStringContainsString("'geraet'", $definition->def);
    }
}
