<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\MediaAsset;
use App\Models\PraxisTask;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Praxistrainer-Karten im Superadmin bearbeiten, anlegen, deaktivieren, Bild tauschen. */
class SuperadminPraxisTaskEditorTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        $this->onAdmin(function () {
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }
            MediaAsset::where('asset_key', 'like', 'praxis-custom-%')->whereNotIn('id', PraxisTask::whereNotNull('media_asset_id')->select('media_asset_id'))->delete();
        });
        parent::tearDown();
    }

    private function makeCourseWithCard(): array
    {
        return $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null, 'code' => 'TEST-PRAXED-'.uniqid(), 'name' => 'Test Praxiseditor', 'course_type' => 'full', 'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;
            $task = PraxisTask::create([
                'course_id' => $course->id, 'content_id' => 'TEST-'.uniqid(), 'kategorie' => 'Seemannsknoten', 'unterkategorie' => 'Palstek',
                'frage' => 'Wie wird ein Palstek gesteckt?', 'antwort' => 'Auge legen, Ende durch …', 'sort_order' => 1,
            ]);

            return [$course, $task];
        });
    }

    public function test_superadmin_edits_and_creates_cards_with_images(): void
    {
        Storage::fake('public');
        $superadmin = $this->createSuperAdmin();
        [$course, $task] = $this->makeCourseWithCard();

        $this->actingAs($superadmin)->get("/superadmin/courses/{$course->id}/praxis-tasks")->assertOk()->assertSee('Wie wird ein Palstek gesteckt?');
        $this->actingAs($superadmin)->get("/superadmin/praxis-tasks/{$task->id}/edit")->assertOk()->assertSee('Palstek');

        $this->actingAs($superadmin)->patch("/superadmin/praxis-tasks/{$task->id}", [
            'kategorie' => 'Seemannsknoten', 'unterkategorie' => 'Palstek', 'frage' => 'Palstek: wie stecken?', 'antwort' => 'Neue Antwort',
            'image' => UploadedFile::fake()->image('palstek.png', 400, 300), 'alt_text' => 'Palstek',
        ])->assertRedirect("/superadmin/courses/{$course->id}/praxis-tasks");
        $task = $this->onAdmin(fn () => $task->fresh('media'));
        $this->assertSame('Palstek: wie stecken?', $task->frage);
        $this->assertSame('Palstek', $task->media->alt_text);
        $firstPath = $task->media->getRawOriginal('storage_path');
        Storage::disk('public')->assertExists($firstPath);

        // Bild ersetzen: das alte, selbst hochgeladene wird gelöscht.
        $this->actingAs($superadmin)->patch("/superadmin/praxis-tasks/{$task->id}", [
            'kategorie' => 'Seemannsknoten', 'frage' => 'Palstek: wie stecken?', 'antwort' => 'Neue Antwort',
            'image' => UploadedFile::fake()->image('palstek2.png', 400, 300),
        ]);
        Storage::disk('public')->assertMissing($firstPath);
        $this->actingAs($superadmin)->patch("/superadmin/praxis-tasks/{$task->id}", [
            'kategorie' => 'Seemannsknoten', 'frage' => 'Palstek: wie stecken?', 'antwort' => 'Neue Antwort', 'remove_image' => '1',
        ]);
        $this->assertNull($this->onAdmin(fn () => $task->fresh())->media_asset_id);

        $this->actingAs($superadmin)->post("/superadmin/courses/{$course->id}/praxis-tasks", [
            'kategorie' => 'Neue Kategorie', 'frage' => 'Neue Frage?', 'antwort' => 'Neue Antwort',
        ])->assertRedirect();
        $new = $this->onAdmin(fn () => PraxisTask::where('course_id', $course->id)->where('frage', 'Neue Frage?')->first());
        $this->assertStringStartsWith('SA-', $new->content_id);
        $this->assertSame(2, $new->sort_order);
        $this->assertTrue($new->active);

        $this->actingAs($superadmin)->post("/superadmin/courses/{$course->id}/praxis-tasks", ['kategorie' => '', 'frage' => '', 'antwort' => ''])
            ->assertSessionHasErrors(['kategorie', 'frage', 'antwort']);
    }

    public function test_deactivated_cards_disappear_for_learners(): void
    {
        $superadmin = $this->createSuperAdmin();
        [$course, $task] = $this->makeCourseWithCard();
        $other = $this->onAdmin(fn () => PraxisTask::create([
            'course_id' => $course->id, 'content_id' => 'TEST-'.uniqid(), 'kategorie' => 'Seemannsknoten', 'frage' => 'Webeleinstek?', 'antwort' => '…', 'sort_order' => 2,
        ]));
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->actingAs($superadmin)->post("/superadmin/praxis-tasks/{$task->id}/toggle-active")->assertRedirect();
        $this->assertFalse($this->onAdmin(fn () => $task->fresh())->active);

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/praxistrainer/{$task->id}")->assertNotFound();
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/praxistrainer")->assertOk()->assertSee('Webeleinstek?')->assertDontSee('Wie wird ein Palstek gesteckt?');
        $this->actingAs($superadmin)->get("/superadmin/courses/{$course->id}/praxis-tasks")->assertSee('deaktiviert');

        $this->actingAs($superadmin)->post("/superadmin/praxis-tasks/{$task->id}/toggle-active");
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/praxistrainer/{$task->id}")->assertOk();
        $this->assertNotNull($other);
    }
}
