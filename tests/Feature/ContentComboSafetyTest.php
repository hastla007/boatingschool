<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PraxisTask;
use App\Models\VideoLesson;
use App\Models\VideoModule;
use Database\Seeders\ComboPraxisTrainerSeeder;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ContentComboSafetyTest extends TestCase
{
    use InteractsWithTenants;

    private array $videoModuleIds = [];

    private array $praxisPrefixes = [];

    private array $renames = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach ($this->renames as $id => $title) {
                VideoModule::whereKey($id)->update(['title' => $title]);
            }
            VideoLesson::whereIn('video_module_id', $this->videoModuleIds)->delete();
            VideoModule::whereIn('id', $this->videoModuleIds)->delete();
            foreach ($this->praxisPrefixes as $p) {
                PraxisTask::where('content_id', 'like', $p.'%')->delete();
            }
            AuditLog::whereIn('actor_user_id', $this->createdUserIds)->delete();
        });
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    /** Neues Kapitel im Kombikurs: nur über hartcodierte Kapiteltitel (ComboVideoGroups) erreichbar -> für Lernende unsichtbar. */
    public function test_chapters_cannot_be_added_directly_to_a_combo_course(): void
    {
        $superadmin = $this->createSuperAdmin();
        $combo = $this->existingCourse('SBF-SEE-BIN');
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant);
        $this->grantEntitlement($tenant, $learner, $combo);

        $this->actingAs($superadmin)->post("/superadmin/courses/{$combo->id}/video-modules", ['title' => 'R8 Gezeiten'])->assertSessionHasErrors('video_module');
        $this->assertFalse($this->onAdmin(fn () => VideoModule::where('course_id', $combo->id)->where('title', 'R8 Gezeiten')->exists()));
    }

    /** Umbenennen des Kapitels "Knoten" (unauditiert) verschiebt es in den Hauptkurs und lässt die Knoten-Kachel verschwinden. */
    public function test_the_knoten_chapter_cannot_be_renamed(): void
    {
        $superadmin = $this->createSuperAdmin();
        $course = $this->existingCourse('SBF-SEE');
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant);
        $this->grantEntitlement($tenant, $learner, $course);
        $knoten = $this->onAdmin(fn () => VideoModule::where('course_id', $course->id)->where('title', 'Knoten')->firstOrFail());
        $this->renames[$knoten->id] = 'Knoten';

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}")->assertSee("kapitel={$knoten->id}", false);
        $this->actingAs($superadmin)->patch("/superadmin/video-modules/{$knoten->id}", ['title' => 'Knoten und Leinen'])->assertSessionHasErrors('video_module');
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}")->assertSee("kapitel={$knoten->id}", false);
    }

    /** Praxiskarte im Teilkurs deaktiviert -> der Kombi-Seeder legt sie in Kombikursen AKTIV an; Änderungen am Original erreichen Kopien nie. */
    public function test_praxis_card_edits_reach_combo_copies_and_deactivated_cards_stay_inactive(): void
    {
        $superadmin = $this->createSuperAdmin();
        $see = $this->existingCourse('SBF-SEE');
        $combo = $this->existingCourse('SBF-SEE-BIN');

        $this->actingAs($superadmin)->post("/superadmin/courses/{$see->id}/praxis-tasks", [
            'kategorie' => 'Pflichtmanöver', 'unterkategorie' => 'R8 Testmanöver '.uniqid(), 'frage' => 'Wie?', 'antwort' => 'Alte Antwort',
        ])->assertSessionHasNoErrors();
        $card = $this->onAdmin(fn () => PraxisTask::where('course_id', $see->id)->where('frage', 'Wie?')->where('antwort', 'Alte Antwort')->latest('id')->firstOrFail());
        $this->praxisPrefixes[] = $card->content_id;

        $this->onAdmin(fn () => (new ComboPraxisTrainerSeeder)->run());
        $copy = $this->onAdmin(fn () => PraxisTask::where('course_id', $combo->id)->where('content_id', $card->content_id.'-SEE-BIN')->first());
        $this->assertNotNull($copy);

        // Superadmin corrects the answer in the source course
        $this->actingAs($superadmin)->patch("/superadmin/praxis-tasks/{$card->id}", [
            'kategorie' => 'Pflichtmanöver', 'unterkategorie' => $card->unterkategorie, 'frage' => 'Wie?', 'antwort' => 'Korrigierte Antwort',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Korrigierte Antwort', $this->onAdmin(fn () => $copy->fresh()->antwort), 'Kopie im Kombikurs wird mitkorrigiert');

        // A new card is created and deactivated (e.g. drafted, not ready) before the next deploy's db:seed
        $this->actingAs($superadmin)->post("/superadmin/courses/{$see->id}/praxis-tasks", [
            'kategorie' => 'Pflichtmanöver', 'unterkategorie' => 'R8 Entwurf '.uniqid(), 'frage' => 'Entwurf?', 'antwort' => 'Noch nicht fertig',
        ])->assertSessionHasNoErrors();
        $draft = $this->onAdmin(fn () => PraxisTask::where('course_id', $see->id)->where('frage', 'Entwurf?')->firstOrFail());
        $this->praxisPrefixes[] = $draft->content_id;
        $this->actingAs($superadmin)->post("/superadmin/praxis-tasks/{$draft->id}/toggle-active");
        $this->assertFalse($this->onAdmin(fn () => (bool) $draft->fresh()->active));
        $this->onAdmin(fn () => (new ComboPraxisTrainerSeeder)->run());
        $draftCopies = $this->onAdmin(fn () => PraxisTask::where('content_id', 'like', $draft->content_id.'-%')->get());
        $this->assertNotEmpty($draftCopies);
        $this->assertTrue($draftCopies->every(fn ($c) => ! $c->active), 'deaktivierte Karte bleibt auch in Kopien aus');
    }
}
