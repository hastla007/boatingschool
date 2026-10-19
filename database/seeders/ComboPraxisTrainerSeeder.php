<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\PraxisTask;
use Illuminate\Database\Seeder;

/**
 * Praxis-Prüfungstrainer für den Kombikurs "SBF See & Binnen": wie Video-
 * und Navigationsinhalte hängen Praxisaufgaben am course_id des jeweiligen
 * Kurses (siehe ComboVideoCourseSeeder/ComboNavigationTaskSeeder). SBF-Sees
 * Aufgaben werden daher unter der eigenen Kurs-ID des Kombikurses
 * gespiegelt, inklusive Verweis auf bereits vorhandene Bild-Assets (keine
 * Dateiduplikate nötig).
 */
class ComboPraxisTrainerSeeder extends Seeder
{
    public function run(): void
    {
        $combo = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE-BIN')->whereNull('tenant_id')->first();
        $sbfSee = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->first();

        if (! $combo || ! $sbfSee || $combo->praxisTasks()->exists()) {
            return;
        }

        foreach ($sbfSee->praxisTasks()->get() as $sourceTask) {
            PraxisTask::create([
                'course_id' => $combo->id,
                'content_id' => $sourceTask->content_id.'-SEE-BIN',
                'kategorie' => $sourceTask->kategorie,
                'unterkategorie' => $sourceTask->unterkategorie,
                'pruefungsbezug' => $sourceTask->pruefungsbezug,
                'aufgabentyp' => $sourceTask->aufgabentyp,
                'frage' => $sourceTask->frage,
                'antwort' => $sourceTask->antwort,
                'erklaerung' => $sourceTask->erklaerung,
                'media_asset_id' => $sourceTask->media_asset_id,
                'quelle' => $sourceTask->quelle,
                'quellen_url' => $sourceTask->quellen_url,
                'sort_order' => $sourceTask->sort_order,
            ]);
        }
    }
}
