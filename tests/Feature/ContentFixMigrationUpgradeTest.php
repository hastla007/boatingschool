<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Korrektur nur per Migration): die Datenkorrektur-Migrationen
 * (BB8-BB12) laufen vor db:seed. Eine Neuinstallation bekommt die
 * korrigierten Texte deshalb aus der Quelle; eine bestehende Installation
 * mit dem alten Text aus der Migration -- ohne die Revision als
 * Editor-Korrektur zu markieren, sonst blockiert sie amtliche
 * Katalogkorrekturen (CatalogUpdateSeeder überspringt edited_by_superadmin).
 */
class ContentFixMigrationUpgradeTest extends TestCase
{
    use InteractsWithTenants;

    private function runMigration(string $number): void
    {
        $migration = require glob(database_path("migrations/2024_02_01_{$number}_*.php"))[0];
        $migration->up();
    }

    public function test_a_fresh_install_gets_the_corrected_texts_from_the_source(): void
    {
        $this->onAdmin(function () {
            $src0099 = ContentQuestion::where('content_id', 'SRC-0099')->firstOrFail()->publishedRevision();
            $see0140 = ContentQuestion::where('content_id', 'SBF-SEE-0140')->firstOrFail()->publishedRevision();
            $segel0263 = ContentQuestion::where('content_id', 'SBF-SEGEL-0263')->firstOrFail()->publishedRevision();

            $this->assertStringContainsString('Dringlichkeitsmeldung', $src0099->question_text);
            $this->assertSame('Welche Bedeutung hat folgendes Schallsignal (mindestens fünf kurze Töne)?', $see0140->question_text);
            $this->assertStringNotContainsString('Gewischt', $segel0263->feedback_correct.$segel0263->feedback_incorrect);
            $this->assertFalse($src0099->edited_by_superadmin);
        });
    }

    public function test_an_existing_install_is_corrected_without_marking_an_editor_change(): void
    {
        $this->onAdmin(function () {
            $question = ContentQuestion::where('content_id', 'SRC-0099')->firstOrFail();
            $published = $question->publishedRevision();
            $corrected = $published->question_text;
            $published->update(['question_text' => str_replace('Dringlichkeitsmeldung', 'Dringlichkeits- meldung', $corrected)]);

            try {
                $this->runMigration('000488');

                $fixed = $question->fresh()->publishedRevision();
                $this->assertSame($corrected, $fixed->question_text);
                $this->assertFalse($fixed->edited_by_superadmin);
                $this->assertSame($published->source_version, $fixed->source_version);
                $this->assertSame('deprecated', $published->fresh()->editorial_status);
            } finally {
                $question->revisions()->where('id', '!=', $published->id)->where('revision_no', '>', $published->revision_no)->each(function ($revision) {
                    $revision->answers()->delete();
                    $revision->media()->detach();
                    $revision->delete();
                });
                // Direkt per Query: das Modell hält editorial_status noch als
                // "published" im Speicher, update() sähe dort keine Änderung.
                ContentQuestionRevision::whereKey($published->id)->update(['question_text' => $corrected, 'editorial_status' => 'published']);
            }
        });
    }
}
