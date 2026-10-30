<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\CourseDefinition;
use App\Models\Favorite;
use App\Models\PraxisFormTemplate;
use App\Models\Progress;
use App\Models\Pruefungsausschuss;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DemoLearningActivitySeeder;
use Database\Seeders\PraxisFormTemplateSeeder;
use Database\Seeders\PruefungsausschussSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Seeder nicht idempotent auf bestehenden Installationen):
 * db:seed läuft bei jedem Deploy erneut. PraxisFormTemplateSeeder legte ein
 * vom Superadmin umbenanntes Formular erneut an (zwei aktive Formulare je
 * Kurs), PruefungsausschussSeeder holte gelöschte Prüfungsausschüsse
 * zurück bzw. duplizierte umbenannte, und DemoLearningActivitySeeder
 * vermehrte bei jedem Lauf die Demo-Lernaktivität.
 *
 * Jeder Test läuft in einer Transaktion auf der Owner-Verbindung, die am
 * Ende zurückgerollt wird -- der geseedete Katalog der Testdatenbank bleibt
 * unverändert.
 */
class SeedersAreIdempotentOnExistingInstallationsTest extends TestCase
{
    use InteractsWithTenants;

    private function inRolledBackAdminTransaction(callable $callback): void
    {
        $this->onAdmin(function () use ($callback) {
            DB::beginTransaction();

            try {
                $callback();
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_a_renamed_praxis_form_is_not_seeded_a_second_time(): void
    {
        $this->inRolledBackAdminTransaction(function () {
            $template = PraxisFormTemplate::where('title', 'Praxis & Prüfung Motorboot')->first();
            if (! $template) {
                $this->markTestSkipped('Katalog ohne geseedetes Formular "Praxis & Prüfung Motorboot".');
            }

            $template->update(['title' => 'Praxisprüfung Motorboot (umbenannt)']);
            $before = PraxisFormTemplate::count();

            app(PraxisFormTemplateSeeder::class)->run();

            $this->assertSame($before, PraxisFormTemplate::count());
            $this->assertFalse(PraxisFormTemplate::where('title', 'Praxis & Prüfung Motorboot')->exists());

            $sbfSee = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->where('code', 'SBF-SEE')->value('id');
            $activeForSbfSee = PraxisFormTemplate::where('active', true)->get()
                ->filter(fn (PraxisFormTemplate $t) => in_array($sbfSee, $t->course_ids ?? [], true));
            $this->assertCount(1, $activeForSbfSee);
        });
    }

    public function test_a_missing_praxis_form_for_unassigned_courses_is_still_seeded(): void
    {
        $this->inRolledBackAdminTransaction(function () {
            // Ein im Code neu hinzugekommenes Formular (hier simuliert durch
            // Entfernen eines bestehenden) muss weiterhin angelegt werden.
            PraxisFormTemplate::where('title', 'Anmeldung Pyroschein-Prüfung')->whereDoesntHave('submissions')->delete();
            if (PraxisFormTemplate::where('title', 'Anmeldung Pyroschein-Prüfung')->exists()) {
                $this->markTestSkipped('Formular hat Anmeldungen und lässt sich hier nicht entfernen.');
            }

            app(PraxisFormTemplateSeeder::class)->run();

            $this->assertTrue(PraxisFormTemplate::where('title', 'Anmeldung Pyroschein-Prüfung')->exists());
        });
    }

    public function test_deleted_and_renamed_pruefungsausschuesse_stay_deleted_and_renamed(): void
    {
        $this->inRolledBackAdminTransaction(function () {
            [$deleted, $renamed] = Pruefungsausschuss::orderBy('sort_order')->take(2)->get()->all() + [null, null];
            if (! $deleted || ! $renamed) {
                $this->markTestSkipped('Keine geseedeten Prüfungsausschüsse vorhanden.');
            }

            $deletedKey = [$deleted->kommission, $deleted->standorttyp];
            $renamedKey = [$renamed->kommission, $renamed->standorttyp];

            $deleted->delete();
            $renamed->update(['kommission' => 'Umbenannter Ausschuss (Test)']);
            $before = Pruefungsausschuss::count();

            app(PruefungsausschussSeeder::class)->run();

            $this->assertSame($before, Pruefungsausschuss::count());
            $this->assertFalse(Pruefungsausschuss::where('kommission', $deletedKey[0])->where('standorttyp', $deletedKey[1])->exists());
            $this->assertFalse(Pruefungsausschuss::where('kommission', $renamedKey[0])->where('standorttyp', $renamedKey[1])->exists());
        });
    }

    public function test_an_empty_pruefungsausschuss_table_is_still_seeded(): void
    {
        $this->inRolledBackAdminTransaction(function () {
            DB::table('tenant_course_location')->update(['pruefungsausschuss_id' => null]);
            Pruefungsausschuss::query()->delete();

            app(PruefungsausschussSeeder::class)->run();

            $this->assertGreaterThan(0, Pruefungsausschuss::count());
        });
    }

    public function test_rerunning_the_demo_learning_activity_seeder_does_not_multiply_activity(): void
    {
        $this->inRolledBackAdminTransaction(function () {
            $tenant = Tenant::where('slug', 'mueller')->first();
            $user = User::where('email', 'max@bootsschule-mueller.de')->first();
            if (! $tenant || ! $user) {
                $this->markTestSkipped('Demo-Bootsschulen sind in dieser Datenbank nicht geseedet.');
            }

            $counts = fn () => [
                Attempt::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->count(),
                Favorite::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->count(),
                Progress::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->sum('current_streak'),
            ];

            // Sicherstellen, dass der Demo-Nutzer mindestens einmal bestückt
            // ist (erster Lauf), dann zählt nur der Vergleich zum zweiten.
            app()->call([app(DemoLearningActivitySeeder::class), 'run']);
            $afterFirstRun = $counts();
            $this->assertGreaterThan(0, $afterFirstRun[0]);

            app()->call([app(DemoLearningActivitySeeder::class), 'run']);

            $this->assertSame($afterFirstRun, $counts());
        });
    }
}
