<?php

namespace Database\Seeders;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\ExamBlueprint;
use App\Models\ExamRuleSet;
use App\Models\Module;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ergänzung SRC → UBI (ADD-UBI-TO-SRC) auf den amtlichen
 * Ergänzungskatalog beschränken: die 79 Fragen aus
 * ubi_ergaenzung_katalog.php bilden das Modul UBI_ERG (dieselben
 * content_question-Zeilen wie im Modul UBI, keine Kopien -- Lernfortschritt
 * und Revisionen bleiben gemeinsam).
 *
 * Bis Oktober 2026 hing der Kurs am vollständigen Modul UBI (130 Fragen),
 * weil keine amtliche Abgrenzung vorlag (siehe Blatt "Ergänzungskurs
 * Entscheidung" in zentrale_fragenbasis.xlsx: "bleibt der UBI-
 * Ergänzungskurs prüfungsorientiert vollständig"). Auf bestehenden
 * Installationen tauscht dieser Seeder deshalb das Modul UBI gegen UBI_ERG
 * und entfernt den Zufallsprüfungs-Blueprint auf UBI, damit
 * ExamRuleSetSeeder (läuft danach) ihn für UBI_ERG neu anlegt.
 *
 * Idempotent; muss nach content:import und CourseSeeder laufen.
 */
class UbiErgaenzungSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = include database_path('data/ubi_ergaenzung_katalog.php');
        $questionIds = ContentQuestion::where('content_role', 'ubi_official')->pluck('id', 'official_number');

        $missing = array_diff(array_map('strval', $catalog), $questionIds->keys()->all());
        if ($missing !== []) {
            $this->command?->warn('UBI-Ergänzungskatalog übersprungen: Katalogfragen '.implode(', ', $missing).' fehlen.');

            return;
        }

        DB::transaction(function () use ($catalog, $questionIds) {
            $module = Module::firstOrCreate(
                ['code' => 'UBI_ERG'],
                ['name' => 'UBI Ergänzung (für Inhaber eines Seefunkzeugnisses)', 'status' => 'published']
            );

            $pivot = [];
            foreach ($catalog as $ergNumber => $officialNumber) {
                $pivot[$questionIds[(string) $officialNumber]] = ['sort_order' => $ergNumber, 'required' => true];
            }
            $module->questions()->syncWithoutDetaching($pivot);

            $course = CourseDefinition::withoutGlobalScopes()->where('code', 'ADD-UBI-TO-SRC')->whereNull('tenant_id')->first();
            $fullModule = Module::where('code', 'UBI')->first();

            if (! $course || ! $fullModule || ! $course->modules()->whereKey($fullModule->id)->exists()) {
                return;
            }

            $sortOrder = $course->modules()->whereKey($fullModule->id)->first()->pivot->sort_order;
            $course->modules()->detach($fullModule->id);
            $course->modules()->syncWithoutDetaching([$module->id => ['sort_order' => $sortOrder, 'required' => true]]);

            ExamBlueprint::whereIn('rule_set_id', ExamRuleSet::where('course_id', $course->id)->pluck('id'))
                ->where('module_id', $fullModule->id)
                ->delete();
        });
    }
}
