<?php

namespace Tests\Feature;

use App\Models\PraxisTask;
use Database\Seeders\ComboPraxisTrainerSeeder;
use Database\Seeders\ElwisPraxisImageSeeder;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Kombikurs ohne Binnen-Praxiskarten): der Kombikurs "SBF See &
 * Binnen" bekam bisher nur die 108 SBF-See-Karten gespiegelt -- die
 * Binnen-eigenen Karten (Schallzeichen, Tafelzeichen, Brücken und
 * Schleusen, Lichter, Fahrrinne) fehlten. Manöver, Knoten und Sicherheit
 * kommen nur einmal (aus SBF See), sonst stünden sie doppelt da. Bestehende Installationen (mit den 108
 * See-Karten) müssen die fehlenden Binnen-Karten beim erneuten Seeden
 * ergänzt bekommen, statt dass der Seeder wegen vorhandener Karten
 * abbricht.
 */
class ComboPraxisTrainerSeederTest extends TestCase
{
    use InteractsWithTenants;

    public function test_the_combo_course_has_the_see_and_the_binnen_cards(): void
    {
        $this->onAdmin(function () {
            $tasks = $this->existingCourse('SBF-SEE-BIN')->praxisTasks()->get();

            $this->assertCount(108 + 89, $tasks);
            $this->assertCount(89, $tasks->filter(fn ($task) => str_starts_with($task->content_id, 'PRAXIS-BIN-') && str_ends_with($task->content_id, '-SEE-BIN')));
            $this->assertCount(5, $tasks->where('kategorie', 'Pflichtmanöver'), 'Pflichtmanöver nur einmal (aus SBF See).');
            $this->assertCount(9, $tasks->where('kategorie', 'Seemannsknoten'));
            // Binnen-eigene Sicherheitskarten kommen dazu, gleiche nicht doppelt.
            $this->assertTrue($tasks->contains('content_id', 'PRAXIS-BIN-0115-SEE-BIN'));
            $this->assertTrue($tasks->contains('content_id', 'PRAXIS-BIN-0116-SEE-BIN'));
            $this->assertCount(12, $tasks->where('kategorie', 'Sicherheit und Crew'));
            $this->assertSame($tasks->count(), $tasks->pluck('content_id')->unique()->count());

            // Binnen-Karten folgen hinter den See-Karten.
            $this->assertSame('PRAXIS-0001-SEE-BIN', $tasks->first()->content_id);
            $this->assertSame('PRAXIS-BIN-0018-SEE-BIN', $tasks[108]->content_id);

            // Bild-Assets werden geteilt, nicht kopiert.
            $source = PraxisTask::where('content_id', 'PRAXIS-BIN-0030')->firstOrFail();
            $mirror = PraxisTask::where('content_id', 'PRAXIS-BIN-0030-SEE-BIN')->firstOrFail();
            $this->assertSame($source->media_asset_id, $mirror->media_asset_id);
            $this->assertSame($source->frage, $mirror->frage);
        });
    }

    public function test_reseeding_adds_missing_cards_without_duplicating_existing_ones(): void
    {
        $this->onAdmin(function () {
            $combo = $this->existingCourse('SBF-SEE-BIN');

            // Stand einer Installation, die nur die See-Karten gespiegelt hat.
            PraxisTask::where('course_id', $combo->id)->where('content_id', 'like', 'PRAXIS-BIN-%')->delete();
            $this->assertSame(108, $combo->praxisTasks()->count());

            (new ComboPraxisTrainerSeeder)->run();
            (new ComboPraxisTrainerSeeder)->run();
            (new ElwisPraxisImageSeeder)->run();

            $this->assertSame(197, $combo->praxisTasks()->count());
            $this->assertSame(5, $this->existingCourse('ADD-BIN-TO-SEE')->praxisTasks()->count());
            $this->assertSame(
                PraxisTask::where('content_id', 'PRAXIS-BIN-0018')->value('media_asset_id'),
                PraxisTask::where('content_id', 'PRAXIS-BIN-0018-SEE-BIN')->value('media_asset_id')
            );
        });
    }
}
