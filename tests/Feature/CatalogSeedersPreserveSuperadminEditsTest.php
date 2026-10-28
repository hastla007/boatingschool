<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\ExamBlueprint;
use App\Models\ExamRuleSet;
use App\Models\Module;
use App\Models\PraxisFormTemplate;
use App\Models\Pruefungsausschuss;
use Database\Seeders\CourseSeeder;
use Database\Seeders\ExamRuleSetSeeder;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PraxisFormTemplateSeeder;
use Database\Seeders\PruefungsausschussSeeder;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Hoch): CourseSeeder, ModuleSeeder, ExamRuleSetSeeder und
 * PraxisFormTemplateSeeder laufen nicht nur einmal auf einer frischen
 * Installation -- sie werden in Produktion erneut ausgeführt, sobald ein
 * neues Kursprodukt/Modul/Formular/Regelwerk im Code dazukommt (die
 * einzige Art, wie ein solcher globaler Eintrag hier entsteht). Die
 * ursprüngliche updateOrCreate()-Fassung überschrieb dabei BEI JEDEM
 * erneuten Lauf die mutable Attribute JEDES bereits existierenden
 * Eintrags mit den fest im Seeder-Code hinterlegten Startwerten --
 * Kursstatus, Modulnamen, Praxisformular-Felder, Regelwerke und per
 * detachModule() entfernte Modulzuordnungen, die ein Superadmin über das
 * jeweilige Superadmin-UI geändert hatte, sprangen dadurch lautlos auf
 * den Startwert zurück. firstOrCreate() lässt einen bereits existierenden
 * Eintrag unberührt und erzeugt nur wirklich neue Einträge -- genau das,
 * was ein Reseed für neue Katalogeinträge braucht, ohne spätere
 * Superadmin-Änderungen an bereits vorhandenen Einträgen zu überschreiben.
 *
 * Bug-Review (Seeder überschreibt Superadmin-Änderungen): PruefungsausschussSeeder
 * wurde beim ursprünglichen Fix oben übersehen -- läuft erneut, sobald die
 * DMYV-Liste aktualisiert wird, und überschrieb bis dahin dieselben
 * mutable Attribute (Adresse, Telefon, Sprechzeiten, ...) jedes bereits
 * existierenden Prüfungsausschusses bei jedem Lauf.
 *
 * Jeder Test mutiert hier einen von der Testsuite bereits angelegten,
 * dauerhaften Katalogeintrag (kein RefreshDatabase/DatabaseTransactions in
 * dieser Suite, siehe tests/TestCase.php) und stellt den Originalwert in
 * tearDown() wieder her, um die geteilte Datenbank nicht zu verändern.
 */
class CatalogSeedersPreserveSuperadminEditsTest extends TestCase
{
    use InteractsWithTenants;

    private array $restore = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach (array_reverse($this->restore) as $callback) {
                $callback();
            }
        });

        parent::tearDown();
    }

    /**
     * Alle Schreibzugriffe (Mutation, Wiederherstellung UND der Seeder-Lauf
     * selbst) brauchen die Owner-Verbindung -- der Webprozess läuft sonst
     * auf der eingeschränkten App-Rolle, die auf diesen globalen
     * Katalogtabellen keine Schreibrechte hat (Sicherheitsreview M6).
     */
    public function test_reseeding_courses_does_not_reset_a_course_status_changed_by_a_superadmin(): void
    {
        $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->where('code', 'SBF-SEE')->firstOrFail();
            $originalStatus = $course->status;
            $originalName = $course->name;

            $course->forceFill(['status' => 'archived', 'name' => 'SBF See (vom Superadmin umbenannt)'])->save();
            $this->restore[] = fn () => $course->forceFill(['status' => $originalStatus, 'name' => $originalName])->save();

            (new CourseSeeder)->run();

            $fresh = $course->fresh();
            $this->assertSame('archived', $fresh->status, 'Ein erneuter Seed darf einen vom Superadmin geänderten Kursstatus nicht zurücksetzen.');
            $this->assertSame('SBF See (vom Superadmin umbenannt)', $fresh->name);
        });
    }

    public function test_reseeding_courses_does_not_reattach_a_module_removed_by_a_superadmin(): void
    {
        $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->where('code', 'SBF-BIN-MOTOR')->firstOrFail();
            $module = Module::where('code', 'SBF_BINNEN')->firstOrFail();

            $pivot = $course->modules()->where('module.id', $module->id)->firstOrFail()->pivot;
            $this->restore[] = fn () => $course->modules()->syncWithoutDetaching([
                $module->id => ['sort_order' => $pivot->sort_order, 'required' => $pivot->required],
            ]);

            $course->modules()->detach($module->id);

            (new CourseSeeder)->run();

            $this->assertFalse(
                $course->modules()->where('module.id', $module->id)->exists(),
                'Ein per detachModule() entferntes Modul darf durch einen erneuten Seed nicht wieder angehängt werden.'
            );
        });
    }

    public function test_reseeding_modules_does_not_reset_a_module_name_changed_by_a_superadmin(): void
    {
        $this->onAdmin(function () {
            $module = Module::where('code', 'SBF_BASIS')->firstOrFail();
            $originalName = $module->name;

            $module->forceFill(['name' => 'SBF Basis (vom Superadmin umbenannt)'])->save();
            $this->restore[] = fn () => $module->forceFill(['name' => $originalName])->save();

            (new ModuleSeeder)->run();

            $this->assertSame('SBF Basis (vom Superadmin umbenannt)', $module->fresh()->name);
        });
    }

    public function test_reseeding_exam_rule_sets_does_not_reset_a_passing_rule_entered_after_review(): void
    {
        $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->where('code', 'FKN')->firstOrFail();
            $ruleSet = ExamRuleSet::where('course_id', $course->id)->where('version', 'draft-2026-09')->firstOrFail();
            $originalRule = $ruleSet->passing_rule;

            $ruleSet->forceFill(['passing_rule' => ['min_correct_ratio' => 0.5]])->save();
            $this->restore[] = fn () => $ruleSet->forceFill(['passing_rule' => $originalRule])->save();

            (new ExamRuleSetSeeder)->run();

            $this->assertSame(['min_correct_ratio' => 0.5], $ruleSet->fresh()->passing_rule);
        });
    }

    public function test_reseeding_exam_blueprints_does_not_reset_a_question_count_changed_after_review(): void
    {
        $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->where('code', 'FKN')->firstOrFail();
            $ruleSet = ExamRuleSet::where('course_id', $course->id)->where('version', 'draft-2026-09')->firstOrFail();
            $blueprint = ExamBlueprint::where('rule_set_id', $ruleSet->id)->firstOrFail();
            $originalCount = $blueprint->question_count;

            $blueprint->forceFill(['question_count' => $originalCount + 1])->save();
            $this->restore[] = fn () => $blueprint->forceFill(['question_count' => $originalCount])->save();

            (new ExamRuleSetSeeder)->run();

            $this->assertSame($originalCount + 1, $blueprint->fresh()->question_count);
        });
    }

    public function test_reseeding_praxis_form_templates_does_not_reset_fields_edited_by_a_superadmin(): void
    {
        $this->onAdmin(function () {
            $template = PraxisFormTemplate::where('title', 'Praxis & Prüfung Motorboot')->firstOrFail();
            $originalMinProgress = $template->min_progress_percent;

            $template->forceFill(['min_progress_percent' => 99])->save();
            $this->restore[] = fn () => $template->forceFill(['min_progress_percent' => $originalMinProgress])->save();

            (new PraxisFormTemplateSeeder)->run();

            $this->assertSame(99, $template->fresh()->min_progress_percent);
        });
    }

    public function test_reseeding_pruefungsausschuesse_does_not_reset_an_address_corrected_by_a_superadmin(): void
    {
        $this->onAdmin(function () {
            $pa = Pruefungsausschuss::where('kommission', 'Prüfungszentrum NRW')->firstOrFail();
            $originalTelefon = $pa->telefon;
            $originalStadt = $pa->stadt;

            $pa->forceFill(['telefon' => '+49 000 000 0000', 'stadt' => 'Korrigierte Stadt'])->save();
            $this->restore[] = fn () => $pa->forceFill(['telefon' => $originalTelefon, 'stadt' => $originalStadt])->save();

            (new PruefungsausschussSeeder)->run();

            $fresh = $pa->fresh();
            $this->assertSame('+49 000 000 0000', $fresh->telefon, 'Ein erneuter Seed darf eine vom Superadmin korrigierte Telefonnummer nicht zurücksetzen.');
            $this->assertSame('Korrigierte Stadt', $fresh->stadt);
        });
    }
}
