<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\PraxisTask;
use Illuminate\Database\Seeder;

/**
 * Praxis-Prüfungstrainer für den Kombikurs "SBF See & Binnen" und die
 * Ergänzung Binnen -> See: wie Video- und Navigationsinhalte hängen
 * Praxisaufgaben am course_id des jeweiligen Kurses (siehe
 * ComboVideoCourseSeeder/ComboNavigationTaskSeeder). SBF-Sees Aufgaben
 * werden daher unter der eigenen Kurs-ID gespiegelt, inklusive Verweis auf
 * bereits vorhandene Bild-Assets (keine Dateiduplikate nötig). Inhaber des
 * SBF Binnen (Antriebsmaschine) fahren für SBF See praktisch nur die
 * Pflichtmanöver (SpFV Anlage 4) -- die Ergänzung bekommt nur diese.
 */
class ComboPraxisTrainerSeeder extends Seeder
{
    /** Kurs-Code => [Suffix der content_id, nur diese Kategorie (null = alle)]. */
    private const TARGETS = [
        'SBF-SEE-BIN' => ['-SEE-BIN', null],
        'ADD-BIN-TO-SEE' => ['-BIN-SEE', 'Pflichtmanöver'],
    ];

    public function run(): void
    {
        $sbfSee = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->first();

        if (! $sbfSee) {
            return;
        }

        foreach (self::TARGETS as $code => [$suffix, $kategorie]) {
            $target = CourseDefinition::withoutGlobalScopes()->where('code', $code)->whereNull('tenant_id')->first();

            if ($target && ! $target->praxisTasks()->exists()) {
                $this->mirror($sbfSee, $target, $suffix, $kategorie);
            }
        }
    }

    private function mirror(CourseDefinition $sbfSee, CourseDefinition $target, string $suffix, ?string $kategorie): void
    {
        foreach ($sbfSee->praxisTasks()->when($kategorie, fn ($q) => $q->where('kategorie', $kategorie))->get() as $sourceTask) {
            PraxisTask::create([
                'course_id' => $target->id,
                'content_id' => $sourceTask->content_id.$suffix,
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
