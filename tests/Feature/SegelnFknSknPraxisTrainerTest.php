<?php

namespace Tests\Feature;

use App\Models\PraxisTask;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Praxistrainer für SBF Binnen unter Segel, FKN und SKN samt Ergänzungen
 * (database/data/sbf_binnen_segeln_praxistrainer.csv, fkn_praxistrainer.csv,
 * skn_praxistrainer.csv; Spiegelung in ComboPraxisTrainerSeeder).
 */
class SegelnFknSknPraxisTrainerTest extends TestCase
{
    use InteractsWithTenants;

    public function test_sbf_binnen_unter_segel_has_the_sail_cards_and_the_binnen_cards_without_engine_manoeuvres(): void
    {
        $this->onAdmin(function () {
            $tasks = $this->existingCourse('SBF-BIN-SEGEL')->praxisTasks()->with('media')->get();

            $this->assertCount(48 + 105, $tasks);
            $this->assertCount(48, $tasks->filter(fn ($task) => str_starts_with($task->content_id, 'PRAXIS-SEGEL-')));
            $this->assertSame('Rettungsmanöver unter Segel', $tasks->first()->unterkategorie);

            // Unter Segel keine Manöver unter Antriebsmaschine und keine Motorboot-Karten.
            $this->assertCount(0, $tasks->whereIn('kategorie', ['Pflichtmanöver', 'Sonstige Manöver']));
            $this->assertCount(0, $tasks->whereIn('unterkategorie', ['Quickstop/Notstopp', 'Propellergefahr', 'Sog und Wellenschlag']));
            // Knoten, Zeichen und Sicherheit gelten auch unter Segel.
            $this->assertCount(9, $tasks->where('kategorie', 'Seemannsknoten'));
            $this->assertTrue($tasks->contains('content_id', 'PRAXIS-BIN-0116-SEGEL'));

            $this->assertSame($tasks->count(), $tasks->pluck('content_id')->unique()->count());
            $this->assertSame([], $tasks->where('aufgabentyp', 'Bildfrage')->filter(fn ($task) => ! $task->media)->pluck('content_id')->values()->all());
            $this->assertSame('elwis/sbf_binnen_segeln_295_1.png', $tasks->firstWhere('unterkategorie', 'Manöver im Kursverlauf')->media->asset_key);
            $this->assertSame('elwis/sbf_binnen_185_1.png', $tasks->firstWhere('unterkategorie', 'Wind von Backbord')->media->asset_key);
        });
    }

    public function test_the_sail_addon_trains_only_the_sail_cards(): void
    {
        $this->onAdmin(function () {
            $tasks = $this->existingCourse('ADD-BIN-SEGEL')->praxisTasks()->orderBy('sort_order')->get();

            $this->assertCount(48, $tasks);
            $this->assertTrue($tasks->every(fn ($task) => str_starts_with($task->content_id, 'PRAXIS-SEGEL-') && str_ends_with($task->content_id, '-ADD-SEGEL')));

            $source = PraxisTask::where('content_id', 'PRAXIS-SEGEL-0012')->firstOrFail();
            $mirror = $tasks->firstWhere('content_id', 'PRAXIS-SEGEL-0012-ADD-SEGEL');
            $this->assertSame($source->media_asset_id, $mirror->media_asset_id);
            $this->assertNotNull($mirror->media_asset_id);
        });
    }

    public function test_skn_includes_the_fkn_cards_and_the_addon_only_the_signal_pistol_cards(): void
    {
        $this->onAdmin(function () {
            $fkn = $this->existingCourse('FKN')->praxisTasks;
            $skn = $this->existingCourse('SKN')->praxisTasks()->orderBy('sort_order')->get();
            $addon = $this->existingCourse('ADD-FKN-TO-SKN')->praxisTasks;

            $this->assertCount(24, $fkn);
            $this->assertCount(22 + 24, $skn);
            $this->assertCount(24, $skn->filter(fn ($task) => str_ends_with($task->content_id, '-SKN') && str_starts_with($task->content_id, 'PRAXIS-FKN-')));
            $this->assertSame('PRAXIS-SKN-0001', $skn->first()->content_id);

            $this->assertCount(22, $addon);
            $this->assertEqualsCanonicalizing(
                ['Signalpistole handhaben', 'Munition Kaliber 4', 'Aufbewahrung und Transport', 'Waffenrecht'],
                $addon->pluck('kategorie')->unique()->values()->all()
            );

            // Antworten folgen dem amtlichen Katalog (SKN Frage 72/73).
            $this->assertStringContainsString('frühestens nach 1 Minute', $skn->firstWhere('unterkategorie', 'Versager in der Signalpistole')->antwort);
            $this->assertStringContainsString('nach Lee', $fkn->firstWhere('unterkategorie', 'Handfackel abbrennen')->antwort);
        });
    }

    public function test_learner_sees_a_neutral_praxis_trainer_title_outside_the_sbf_courses(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SKN');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/learn/overview")
            ->assertOk()->assertSee('Trainer für die praktische Prüfung')->assertDontSee('praktische Prüfung SBF')
            ->assertSee('Signalpistole handhaben')->assertSee('Seenotsignalmittel erkennen');
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/praxistrainer?kategorie=Signalpistole handhaben")
            ->assertOk()->assertSee('Grundregeln');
    }

    public function test_the_card_files_are_well_formed(): void
    {
        foreach (['sbf_binnen_segeln_praxistrainer.csv' => 48, 'fkn_praxistrainer.csv' => 24, 'skn_praxistrainer.csv' => 22] as $file => $expected) {
            $handle = fopen(database_path('data/'.$file), 'r');
            $header = fgetcsv($handle, 0, ';');
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            $rows = [];
            while (($data = fgetcsv($handle, 0, ';')) !== false) {
                $this->assertCount(count($header), $data, $file.': '.($data[0] ?? '?'));
                $rows[] = array_combine($header, $data);
            }
            fclose($handle);

            $this->assertCount($expected, $rows, $file);
            $this->assertSame(count($rows), count(array_unique(array_column($rows, 'id'))), $file);
            foreach ($rows as $row) {
                foreach (['kategorie', 'unterkategorie', 'frage', 'antwort', 'erklaerung', 'quelle'] as $field) {
                    $this->assertNotSame('', trim($row[$field]), "{$file} {$row['id']}: {$field}");
                }
            }
        }
    }
}
