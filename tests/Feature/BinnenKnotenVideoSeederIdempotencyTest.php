<?php

namespace Tests\Feature;

use App\Models\VideoModule;
use Database\Seeders\BinnenKnotenVideoSeeder;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Seeder legt umbenannte Kapitel erneut an): BinnenKnoten-
 * VideoSeeder erkannte das Kapitel nur am Titel "Knoten" -- ein vom
 * Superadmin umbenanntes Kapitel kopierte der nächste db:seed erneut.
 */
class BinnenKnotenVideoSeederIdempotencyTest extends TestCase
{
    use InteractsWithTenants;

    public function test_a_renamed_knots_chapter_is_not_copied_again(): void
    {
        $this->onAdmin(function () {
            $binnen = $this->existingCourse('SBF-BIN-MOTOR');
            $knoten = VideoModule::where('course_id', $binnen->id)->where('title', 'Knoten')->firstOrFail();
            $before = VideoModule::where('course_id', $binnen->id)->count();

            $knoten->update(['title' => 'Seemannsknoten (umbenannt)']);

            try {
                (new BinnenKnotenVideoSeeder)->run();

                $this->assertSame($before, VideoModule::where('course_id', $binnen->id)->count());
            } finally {
                $knoten->update(['title' => 'Knoten']);
            }
        });
    }
}
