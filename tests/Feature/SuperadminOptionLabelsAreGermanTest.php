<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Support\StatusLabels;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (englische Optionen in Superadmin-Dropdowns): Kurs-Status
 * (draft/published/archived), Kurstyp (full/addon/combo), Bootsschul-
 * Status (trial/active/...), Rollen (learner/staff/...) und Nutzerstatus
 * (invited/active/...) standen als rohe Enumwerte in den Auswahllisten.
 * Angezeigt wird jetzt das deutsche Label, übermittelt weiterhin der Wert.
 */
class SuperadminOptionLabelsAreGermanTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        if ($this->createdCourseIds !== []) {
            $this->onAdmin(fn () => CourseDefinition::withoutGlobalScopes()->whereIn('id', $this->createdCourseIds)->delete());
        }

        parent::tearDown();
    }

    public function test_course_create_form_shows_german_status_and_type_options(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->get(route('superadmin.courses.create'));

        $response->assertOk();
        $response->assertSee('<option value="draft" selected>Entwurf</option>', false);
        $response->assertSee('<option value="published" >veröffentlicht</option>', false);
        $response->assertSee('<option value="archived" >archiviert</option>', false);
        $response->assertSee('<option value="full" selected>Vollkurs</option>', false);
        $response->assertSee('<option value="combo" >Kombikurs</option>', false);
        $response->assertSee('<option value="addon" >Ergänzungskurs</option>', false);
        $response->assertDontSee('>draft<', false);
        $response->assertDontSee('>published<', false);
    }

    public function test_course_edit_form_and_list_show_german_labels_and_keep_unknown_types(): void
    {
        $superadmin = $this->createSuperAdmin();
        $addon = $this->makeCourse('addon');
        $legacy = $this->makeCourse('sonderkurs');

        $show = $this->actingAs($superadmin)->get(route('superadmin.courses.show', $addon));
        $show->assertOk();
        $show->assertSee('<option value="addon" selected>Ergänzungskurs</option>', false);
        $show->assertSee('<option value="published" selected>veröffentlicht</option>', false);
        $show->assertDontSee('>archived<', false);

        // Ein frei eingetragener Alt-Wert bleibt auswählbar und vorausgewählt.
        $legacyShow = $this->actingAs($superadmin)->get(route('superadmin.courses.show', $legacy));
        $legacyShow->assertSee('<option value="sonderkurs" selected>sonderkurs</option>', false);

        $index = $this->actingAs($superadmin)->get(route('superadmin.courses.index'));
        $index->assertOk();
        $index->assertSee('Ergänzungskurs');
        $index->assertDontSee('>addon</td>', false);
    }

    public function test_tenant_forms_show_german_status_options(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();

        $create = $this->actingAs($superadmin)->get(route('superadmin.tenants.create'));
        $create->assertOk();
        $create->assertSee('<option value="trial" selected>Testphase</option>', false);
        $create->assertSee('<option value="closed" >geschlossen</option>', false);
        $create->assertDontSee('>trial<', false);

        $show = $this->actingAs($superadmin)->get(route('superadmin.tenants.show', $tenant));
        $show->assertOk();
        $show->assertSee('>Testphase</option>', false);
        $show->assertSee('<option value="suspended" ', false);
        $show->assertDontSee('>suspended</option>', false);
    }

    public function test_user_forms_show_german_role_and_status_options(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $create = $this->actingAs($superadmin)->get(route('superadmin.users.create'));
        $create->assertOk();
        $create->assertSee('<option value="learner" >Lerner</option>', false);
        $create->assertSee('<option value="instructor" >Ausbilder</option>', false);
        $create->assertSee('<option value="owner" >Inhaber</option>', false);
        $create->assertDontSee('>learner</option>', false);

        $show = $this->actingAs($superadmin)->get(route('superadmin.users.show', $learner));
        $show->assertOk();
        $show->assertSee('<option value="staff">Mitarbeiter</option>', false);
        $show->assertSee('<option value="instructor">Ausbilder</option>', false);
        $show->assertSee('>eingeladen</option>', false);
        $show->assertSee('<option value="active" selected>aktiv</option>', false);
        $show->assertDontSee('>invited</option>', false);
        $show->assertDontSee('>staff</option>', false);
    }

    public function test_role_dropdown_labels_are_unique(): void
    {
        $labels = StatusLabels::tenantRoleOptions();

        $this->assertSame(['learner', 'staff', 'instructor', 'admin', 'owner', 'support'], array_keys($labels));
        $this->assertSame(count($labels), count(array_unique($labels)));
    }

    private function makeCourse(string $type): CourseDefinition
    {
        return $this->onAdmin(function () use ($type) {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-LABEL-'.uniqid(),
                'name' => 'Test-Kurs Labels '.$type,
                'course_type' => $type,
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            return $course;
        });
    }
}
