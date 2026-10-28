<?php

namespace App\Console\Commands;

use App\Models\ContentQuestionRevision;
use Illuminate\Console\Command;

/**
 * Bug-Review (Hoch): 58 veröffentlichte Fragen haben image_required=true,
 * aber kein angehängtes MediaAsset (question_media) -- der Lerner sieht in
 * Smarttrainer/Prüfungssimulation/Video-Quiz nur den Fragetext, ohne dass
 * irgendwo sichtbar wird, dass eigentlich ein Bild dazugehört (die
 * Foreach-Schleife über revision.media in den Blade-Views rendert bei
 * fehlender Zuordnung einfach nichts). Investigation ergab: das ist kein
 * Code-Fehler, sondern eine echte Datenlücke in den Quelldateien selbst --
 * 56 der 58 Fragen kommen aus den "Binnen"-Prüfungsbogen-CSVs (sbf_binnen_
 * pruefungsboegen.csv u. a.), die strukturell gar keine Bilddateien-Spalte
 * besitzen (anders als sbf_see_pruefungsboegen.csv mit "Bilddateien im
 * ZIP"); die restlichen 2 haben diese Spalte zwar, aber leer. Ohne die
 * echten amtlichen Bilddateien (z. B. von seeundbinnen.de/PA Nord) lässt
 * sich das nicht automatisiert schließen. Dieser Befehl macht die Lücke
 * wenigstens auffindbar, statt sie nur per Ad-hoc-SQL entdecken zu können.
 */
class AuditMissingRequiredImages extends Command
{
    protected $signature = 'content:audit-missing-images';

    protected $description = 'Listet veröffentlichte Fragen mit image_required=true, denen kein Bild zugeordnet ist.';

    public function handle(): int
    {
        config(['database.default' => 'pgsql_admin']);

        $revisions = ContentQuestionRevision::where('image_required', true)
            ->where('editorial_status', 'published')
            ->whereDoesntHave('media')
            ->with('question')
            ->get();

        if ($revisions->isEmpty()) {
            $this->info('Keine veröffentlichte Frage mit image_required=true ohne Bild gefunden.');

            return self::SUCCESS;
        }

        $this->warn("{$revisions->count()} veröffentlichte Frage(n) mit image_required=true ohne Bild:");

        $rows = $revisions
            ->sortBy(fn (ContentQuestionRevision $r) => [$r->question->content_role, $r->question->official_number])
            ->map(fn (ContentQuestionRevision $r) => [
                $r->question->official_number ?? $r->question->content_id,
                $r->question->content_role ?? '—',
                mb_substr($r->question_text, 0, 70),
            ]);

        $this->table(['Fragenkatalog-Nr.', 'content_role', 'Frage'], $rows);

        return self::FAILURE;
    }
}
