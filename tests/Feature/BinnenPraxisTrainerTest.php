<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\PraxisTask;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Praxistrainer SBF Binnen (database/data/sbf_binnen_praxistrainer.csv) und
 * die Navigationsaufgaben der Ergänzung Binnen -> See.
 */
class BinnenPraxisTrainerTest extends TestCase
{
    use InteractsWithTenants;

    public function test_sbf_binnen_has_the_binnen_exam_manoeuvres_knots_and_signals(): void
    {
        $this->onAdmin(function () {
            $tasks = $this->existingCourse('SBF-BIN-MOTOR')->praxisTasks()->with('media')->get();

            $this->assertCount(116, $tasks);
            $this->assertSame(
                ['Rettungsmanöver unter Maschine', 'Anlegen unter Maschine', 'Ablegen unter Maschine'],
                $tasks->where('kategorie', 'Pflichtmanöver')->pluck('unterkategorie')->values()->all(),
                'SBF Binnen hat nur drei Pflichtmanöver -- Steuern nach Kompass und Peilen gehören zu SBF See.'
            );
            $this->assertCount(5, $tasks->where('kategorie', 'Sonstige Manöver'));
            $this->assertCount(9, $tasks->where('kategorie', 'Seemannsknoten'));
            $this->assertSame($tasks->count(), $tasks->pluck('content_id')->unique()->count());
            $this->assertTrue($tasks->every(fn ($task) => str_starts_with($task->content_id, 'PRAXIS-BIN-')));

            // Jede Bildfrage hat ein Bild: ELWIS-Bild der Katalogfrage oder eigene Grafik.
            $withoutImage = $tasks->where('aufgabentyp', 'Bildfrage')->filter(fn ($task) => ! $task->media)->pluck('content_id');
            $this->assertSame([], $withoutImage->values()->all());
            $this->assertSame('elwis/sbf_binnen_162_1.png', $tasks->firstWhere('unterkategorie', 'Wenden über Steuerbord')->media->asset_key);
            $this->assertSame('SBF See Praxistrainer', $tasks->firstWhere('unterkategorie', 'Palstek')->media->source);
        });
    }

    public function test_the_binnen_cards_share_the_see_drawings_instead_of_copying_them(): void
    {
        $this->onAdmin(function () {
            $see = PraxisTask::where('content_id', 'PRAXIS-0013')->firstOrFail();
            $binnen = PraxisTask::where('content_id', 'PRAXIS-BIN-0011')->firstOrFail();

            $this->assertSame('Palstek', $binnen->unterkategorie);
            $this->assertSame($see->media_asset_id, $binnen->media_asset_id);
        });
    }

    public function test_learner_sees_the_binnen_praxistrainer_in_the_learning_overview(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-BIN-MOTOR');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/learn/overview")
            ->assertOk()->assertSee('Trainer für die praktische Prüfung')->assertSee('Schallzeichen Binnen');
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/praxistrainer?kategorie=Pflichtmanöver")
            ->assertOk()->assertSee('Rettungsmanöver unter Maschine')->assertSee('gegen den Strom');
    }

    public function test_binnen_to_see_addon_has_the_official_navigation_tasks(): void
    {
        $addon = CourseDefinition::withoutGlobalScopes()->where('code', 'ADD-BIN-TO-SEE')->whereNull('tenant_id')->firstOrFail();
        $sbfSee = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->firstOrFail();

        $tasks = $addon->navigationTasks;

        $this->assertCount(15, $tasks);
        $tasks->each(fn ($task) => $this->assertSame(9, $task->questions()->count(), "Aufgabe {$task->task_number}"));
        $this->assertSame($sbfSee->navigationTasks->pluck('scenario_text')->all(), $tasks->pluck('scenario_text')->all());

        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $learner, $addon);

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$addon->id}")->assertOk()->assertSee('Navigationsaufgaben');
        $this->actingAsInTenant($learner, $tenant)->get(route('exam.navigation.index', $addon))->assertOk();
    }

    public function test_binnen_to_see_addon_trains_only_the_see_mandatory_manoeuvres(): void
    {
        $this->onAdmin(function () {
            $tasks = $this->existingCourse('ADD-BIN-TO-SEE')->praxisTasks;

            $this->assertSame(
                ['Rettungsmanöver unter Maschine', 'Anlegen unter Maschine', 'Ablegen unter Maschine', 'Steuern nach Kompass', 'Peilen'],
                $tasks->pluck('unterkategorie')->all()
            );
            $this->assertSame(['Pflichtmanöver'], $tasks->pluck('kategorie')->unique()->values()->all());
            $this->assertSame('PRAXIS-0004-BIN-SEE', $tasks[3]->content_id);
            $this->assertSame(PraxisTask::where('content_id', 'PRAXIS-0004')->value('media_asset_id'), $tasks[3]->media_asset_id);
        });
    }
}
