<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Superadmin-Seite gesperrte Kurse ohne published-Filter):
 * CourseEditorController::index() zeigte früher auch draft/archived-Kurse
 * mit demselben "Sitewide"-Schalter (aktiv/deaktiviert) wie echte,
 * angebotene Kurse -- ein solcher Kurs ist laut EntitlementService::
 * isOfferable() (status !== 'published' schließt Angebot/Zugriff IMMER
 * aus) für keinen Mandanten je erreichbar, der Schalter also wirkungslos
 * und irreführend.
 *
 * Bug-Review (AA15: Entwurf/archivierte Kurse verschwinden aus
 * Superadmin-Liste): der damalige Fix blendete dafür draft/archived-Kurse
 * aus der Liste komplett aus -- show()/update() unterstützen weiterhin
 * jeden Status, aber ohne einen Eintrag hier gab es keinen Weg mehr zu
 * ihnen: ein neuer Entwurf war vor seiner ersten Veröffentlichung nie
 * erreichbar, ein zurückgezogener Kurs blieb für immer unauffindbar, auch
 * um ihn erneut zu veröffentlichen. Die Liste zeigt deshalb wieder jeden
 * Status -- die "Sitewide"-Spalte bleibt für sie aber bewusst neutral
 * (kein aktiv/deaktiviert-Badge), um die ursprüngliche Irreführung nicht
 * wieder einzuführen.
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

    public function test_the_course_list_shows_a_draft_course_without_the_misleading_sitewide_badge(): void
    {
        $superadmin = $this->createSuperAdmin();

        $draft = $this->onAdmin(fn () => CourseDefinition::withoutGlobalScopes()->create([
            'tenant_id' => null,
            'code' => 'TEST-DRAFT-'.uniqid(),
            'name' => 'Noch nicht veröffentlichter Testkurs',
            'course_type' => 'full',
            'status' => 'draft',
            'site_enabled' => true,
        ]));
        $this->createdCourseIds[] = $draft->id;

        $response = $this->actingAs($superadmin)->get('/superadmin/courses');

        $response->assertOk();
        $response->assertSee($draft->name);
        $response->assertSee(route('superadmin.courses.show', $draft));
        $this->assertStringNotContainsString('aktiv', $this->rowFor($response->getContent(), $draft->name));
    }

    public function test_the_course_list_shows_an_archived_course_without_the_misleading_sitewide_badge(): void
    {
        $superadmin = $this->createSuperAdmin();

        $archived = $this->onAdmin(fn () => CourseDefinition::withoutGlobalScopes()->create([
            'tenant_id' => null,
            'code' => 'TEST-ARCHIVED-'.uniqid(),
            'name' => 'Archivierter Testkurs',
            'course_type' => 'full',
            'status' => 'archived',
            'site_enabled' => false,
        ]));
        $this->createdCourseIds[] = $archived->id;

        $response = $this->actingAs($superadmin)->get('/superadmin/courses');

        $response->assertOk();
        $response->assertSee($archived->name);
        $response->assertSee(route('superadmin.courses.show', $archived));
        // "aktiv" deckt beide Badge-Texte ab -- "deaktiviert" enthält
        // "aktiv" selbst als Teilstring.
        $this->assertStringNotContainsString('aktiv', $this->rowFor($response->getContent(), $archived->name));
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

    /**
     * Extrahiert die <tr>...</tr>-Zeile, die den gegebenen Kursnamen
     * enthält -- die Seite listet daneben auch echte, bereits
     * veröffentlichte Kurse mit einem "aktiv"-Badge, eine seitenweite
     * Prüfung würde also unabhängig vom eigentlichen Testkurs immer
     * "aktiv" im HTML finden.
     */
    private function rowFor(string $html, string $courseName): string
    {
        preg_match_all('/<tr\b.*?<\/tr>/s', $html, $matches);

        foreach ($matches[0] as $row) {
            if (str_contains($row, $courseName)) {
                return $row;
            }
        }

        $this->fail("Keine Tabellenzeile für '{$courseName}' gefunden.");
    }
}
