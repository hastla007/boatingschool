<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Superadmin-Seite gesperrte Kurse ohne published-Filter):
 * CourseEditorController::index() zeigte bisher auch draft/archived-Kurse
 * mit demselben "Sitewide"-Schalter (aktiv/deaktiviert) wie echte,
 * angebotene Kurse -- ein solcher Kurs ist laut EntitlementService::
 * isOfferable() (status !== 'published' schließt Angebot/Zugriff IMMER
 * aus) für keinen Mandanten je erreichbar, der Schalter also wirkungslos
 * und irreführend.
 */
class SuperadminCourseIndexPublishedFilterTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->whereKey($id)->delete();
            }
        });

        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_the_course_list_hides_a_draft_course(): void
    {
        $superadmin = $this->createSuperAdmin();

        $draft = $this->onAdmin(fn () => CourseDefinition::withoutGlobalScopes()->create([
            'tenant_id' => null,
            'code' => 'TEST-DRAFT-'.uniqid(),
            'name' => 'Noch nicht veröffentlichter Testkurs',
            'course_type' => 'full',
            'status' => 'draft',
        ]));
        $this->createdCourseIds[] = $draft->id;

        $response = $this->actingAs($superadmin)->get('/superadmin/courses');

        $response->assertOk();
        $response->assertDontSee($draft->name);
    }

    public function test_the_course_list_hides_an_archived_course(): void
    {
        $superadmin = $this->createSuperAdmin();

        $archived = $this->onAdmin(fn () => CourseDefinition::withoutGlobalScopes()->create([
            'tenant_id' => null,
            'code' => 'TEST-ARCHIVED-'.uniqid(),
            'name' => 'Archivierter Testkurs',
            'course_type' => 'full',
            'status' => 'archived',
        ]));
        $this->createdCourseIds[] = $archived->id;

        $response = $this->actingAs($superadmin)->get('/superadmin/courses');

        $response->assertOk();
        $response->assertDontSee($archived->name);
    }

    public function test_the_course_list_still_shows_a_published_course(): void
    {
        $superadmin = $this->createSuperAdmin();

        $published = $this->onAdmin(fn () => CourseDefinition::withoutGlobalScopes()->create([
            'tenant_id' => null,
            'code' => 'TEST-PUBLISHED-'.uniqid(),
            'name' => 'Veröffentlichter Testkurs',
            'course_type' => 'full',
            'status' => 'published',
        ]));
        $this->createdCourseIds[] = $published->id;

        $response = $this->actingAs($superadmin)->get('/superadmin/courses');

        $response->assertOk();
        $response->assertSee($published->name);
    }
}
