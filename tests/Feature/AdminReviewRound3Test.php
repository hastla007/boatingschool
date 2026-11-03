<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Models\TenantCourseLocation;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Review-Runde 3 (Schul-Admin/Superadmin): Regressionstests.
 */
class AdminReviewRound3Test extends TestCase
{
    use InteractsWithTenants;

    private array $templateIds = [];

    private array $courseIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        $this->onAdmin(function () {
            PraxisFormTemplate::whereIn('id', $this->templateIds)->delete();
            CourseDefinition::withoutGlobalScopes()->whereIn('id', $this->courseIds)->delete();
        });
        parent::tearDown();
    }

    public function test_a_revoked_entitlement_cannot_be_restored_for_free_via_suspend_and_reactivate(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $entitlement = $this->onAdmin(fn () => Entitlement::create([
            'tenant_id' => $tenant->id, 'user_id' => $learner->id, 'course_id' => $course->id,
            'status' => 'active', 'source_type' => 'manual',
            'valid_from' => now()->subMinute(), 'valid_until' => now()->addDays(200),
        ]));

        $this->actingAsInTenant($admin, $tenant)->patch(route('admin.entitlements.update', $entitlement), ['action' => 'revoke'])->assertSessionHasNoErrors();
        $this->assertSame('revoked', $this->asTenant($tenant, fn () => Entitlement::find($entitlement->id))->status);

        // Die Schule hat keinen einzigen Code.
        $this->assertSame(0, $this->onAdmin(fn () => Coupon::where('tenant_id', $tenant->id)->count()));

        $this->actingAsInTenant($admin, $tenant)->patch(route('admin.entitlements.update', $entitlement), ['action' => 'suspend'])->assertSessionHasErrors('action');
        $this->actingAsInTenant($admin, $tenant)->patch(route('admin.entitlements.update', $entitlement), ['action' => 'reactivate'])->assertSessionHasErrors('action');

        $this->assertSame('revoked', $this->asTenant($tenant, fn () => Entitlement::find($entitlement->id))->status);
    }

    public function test_editing_a_submission_keeps_the_location_after_the_location_was_deleted(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->onAdmin(function () {
            $c = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null, 'code' => 'R3-'.uniqid(), 'name' => 'R3 Kurs', 'course_type' => 'full', 'status' => 'published',
            ]);
            $this->courseIds[] = $c->id;

            return $c;
        });
        $template = $this->onAdmin(function () use ($course) {
            $t = PraxisFormTemplate::create([
                'title' => 'R3', 'min_progress_percent' => 0, 'course_ids' => [$course->id], 'active' => true,
                'fields' => [
                    ['key' => 'telefon', 'label' => 'Telefon', 'type' => 'text', 'required' => true, 'options' => [], 'help_text' => null],
                    ['key' => 'ort', 'label' => 'Ort', 'type' => 'location', 'required' => true, 'options' => [], 'help_text' => null],
                ],
            ]);
            $this->templateIds[] = $t->id;

            return $t;
        });
        $location = $this->onAdmin(fn () => TenantCourseLocation::create([
            'tenant_id' => $tenant->id, 'praxis_ort' => 'Hamburg', 'pruefung_ort' => 'Hamburg-Prüfung', 'course_ids' => [$course->id],
        ]));
        $submission = $this->onAdmin(fn () => PraxisFormSubmission::create([
            'tenant_id' => $tenant->id, 'course_id' => $course->id, 'user_id' => $learner->id,
            'praxis_form_template_id' => $template->id,
            'data' => ['telefon' => '0170', 'ort' => 'Hamburg', 'ort_location_id' => $location->id, 'ort_pruefungsort' => 'Hamburg-Prüfung'],
        ]));

        $this->actingAsInTenant($admin, $tenant)->delete(route('admin.tenant-course-locations.destroy', $location))->assertSessionHasNoErrors();

        // Ohne verbleibende Orte zeigt das Formular kein Ortsfeld; der Admin korrigiert nur die Telefonnummer.
        $edit = $this->actingAsInTenant($admin, $tenant)->get(route('admin.praxis-form-submissions.edit', $submission));
        $edit->assertOk();
        $edit->assertDontSee('name="ort"', false);

        $this->actingAsInTenant($admin, $tenant)->patch(route('admin.praxis-form-submissions.update', $submission), ['telefon' => '0171'])
            ->assertSessionHasNoErrors();

        $data = $this->onAdmin(fn () => $submission->fresh()->data);
        $this->assertSame('0171', $data['telefon']);
        $this->assertSame('Hamburg', $data['ort']);
        $this->assertSame('Hamburg-Prüfung', $data['ort_pruefungsort']);
    }

    private function makeSubmission($tenant, $learner): PraxisFormSubmission
    {
        $course = $this->onAdmin(function () {
            $c = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null, 'code' => 'R3B-'.uniqid(), 'name' => 'R3B Kurs', 'course_type' => 'full', 'status' => 'published',
            ]);
            $this->courseIds[] = $c->id;

            return $c;
        });
        $this->grantEntitlement($tenant, $learner, $course);
        $template = $this->onAdmin(function () use ($course) {
            $t = PraxisFormTemplate::create([
                'title' => 'R3B', 'min_progress_percent' => 0, 'course_ids' => [$course->id], 'active' => true,
                'fields' => [['key' => 'telefon', 'label' => 'Telefon', 'type' => 'text', 'required' => true, 'options' => [], 'help_text' => null]],
            ]);
            $this->templateIds[] = $t->id;

            return $t;
        });

        return $this->onAdmin(fn () => PraxisFormSubmission::create([
            'tenant_id' => $tenant->id, 'course_id' => $course->id, 'user_id' => $learner->id,
            'praxis_form_template_id' => $template->id, 'data' => ['telefon' => '0170'],
        ]));
    }

    public function test_a_long_online_meeting_url_is_stored(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $submission = $this->makeSubmission($tenant, $learner);

        $url = 'https://teams.microsoft.com/l/meetup-join/19%3ameeting_'.str_repeat('a', 60).'%40thread.v2/0?context=%7b%22Tid%22%3a%22'.str_repeat('b', 36).'%22%2c%22Oid%22%3a%22'.str_repeat('c', 36).'%22%7d&x='.str_repeat('d', 40);
        $this->assertGreaterThan(255, strlen($url));
        $this->assertLessThanOrEqual(500, strlen($url));

        $r = $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2027-05-10 09:00', 'slot' => 0, 'location' => 'online', 'online_meeting_url' => $url]
        );
        $r->assertSessionHasNoErrors();
        $this->assertSame($url, $this->onAdmin(fn () => DB::table('praxis_exam_appointment')->where('praxis_form_submission_id', $submission->id)->value('online_meeting_url')));
    }

    public function test_a_too_long_official_number_is_a_validation_error_not_a_500(): void
    {
        $super = $this->createSuperAdmin();
        $module = $this->existingModule('SRC');
        $this->actingAs($super)->post(route('superadmin.questions.store', $module), [
            'question_type' => 'single_choice', 'question_text' => 'R3 Frage?',
            'official_number' => str_repeat('9', 50),
            'answers' => ['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd'], 'correct' => 'A',
        ])->assertSessionHasErrors('official_number');
    }

    public function test_an_expired_suspended_entitlement_can_be_granted_again_from_the_participants_list(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $entitlement = $this->onAdmin(fn () => Entitlement::create([
            'tenant_id' => $tenant->id, 'user_id' => $learner->id, 'course_id' => $course->id,
            'status' => 'suspended', 'source_type' => 'manual',
            'valid_from' => now()->subDays(400), 'valid_until' => now()->subDay(),
        ]));
        $coupon = $this->onAdmin(fn () => Coupon::create(['code' => 'R3DEAD'.strtoupper(substr(uniqid(), -8)), 'course_id' => $course->id, 'tenant_id' => $tenant->id]));

        try {
            $r = $this->actingAsInTenant($admin, $tenant)->patch(route('admin.entitlements.update', $entitlement), ['action' => 'reactivate']);
            $r->assertSessionHasErrors('action');
            $this->assertStringContainsString('Kurs freischalten', session('errors')->first('action'));

            $dash = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=participants');
            $dash->assertOk();
            // Die Auswahl „Kurs freischalten“ bietet den abgelaufenen Kurs wieder an.
            $html = $dash->getContent();
            $this->assertStringContainsString('SRC Vollkurs (1 frei)', $html);
            $this->actingAsInTenant($admin, $tenant)->post(route('admin.entitlements.store'), ['user_id' => $learner->id, 'course_id' => $course->id])->assertSessionHasNoErrors();
            $this->assertSame('active', $this->asTenant($tenant, fn () => Entitlement::find($entitlement->id))->status);
        } finally {
            $this->onAdmin(fn () => Coupon::where('id', $coupon->id)->delete());
        }
    }

    public function test_the_login_on_an_unknown_school_subdomain_says_school_not_found(): void
    {
        $this->get('http://unbekannte-schule.'.config('app.central_domain').'/login')->assertNotFound();
        $this->get('http://'.config('app.central_domain').'/login')->assertOk();
    }

    public function test_dashboard_activity_has_readable_texts_for_every_school_action(): void
    {
        foreach (['coupon.redeem_renewal', 'exam-registration.update', 'invitation.accept', 'praxis-exam-appointment.withdraw', 'praxis-exam-appointment.cancel', 'praxis-form-submission.update', 'praxis-form-submission.delete'] as $action) {
            $text = (new AuditLog(['action' => $action]))->description();
            $this->assertStringNotContainsString($action, $text, $action);
        }
    }

    public function test_changing_the_location_drops_the_old_locations_details(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->onAdmin(function () {
            $c = CourseDefinition::withoutGlobalScopes()->create(['tenant_id' => null, 'code' => 'R4-'.uniqid(), 'name' => 'R4', 'course_type' => 'full', 'status' => 'published']);
            $this->courseIds[] = $c->id;

            return $c;
        });
        $a = $this->onAdmin(fn () => TenantCourseLocation::create(['tenant_id' => $tenant->id, 'course_ids' => [$course->id], 'praxis_ort' => 'Hafen A', 'pruefung_ort' => 'Pruef A', 'theorie_ort' => 'Theorie A']));
        $b = $this->onAdmin(fn () => TenantCourseLocation::create(['tenant_id' => $tenant->id, 'course_ids' => [$course->id], 'praxis_ort' => 'Hafen B', 'pruefung_ort' => 'Pruef B']));
        $template = $this->onAdmin(function () use ($course) {
            $t = PraxisFormTemplate::create(['title' => 'R4', 'min_progress_percent' => 0, 'course_ids' => [$course->id], 'active' => true,
                'fields' => [['key' => 'ort', 'label' => 'Ort', 'type' => 'location', 'required' => true, 'options' => [], 'help_text' => null]]]);
            $this->templateIds[] = $t->id;

            return $t;
        });
        $submission = $this->onAdmin(fn () => PraxisFormSubmission::create(['tenant_id' => $tenant->id, 'course_id' => $course->id, 'user_id' => $learner->id, 'praxis_form_template_id' => $template->id,
            'data' => ['ort' => 'Hafen A', 'ort_location_id' => $a->id, 'ort_theorieort' => 'Theorie A', 'ort_theorieort_adresse' => 'Str A']]));

        $this->actingAsInTenant($admin, $tenant)->patch(route('admin.praxis-form-submissions.update', $submission), ['ort' => $b->id])->assertRedirect();
        $data = $this->onAdmin(fn () => PraxisFormSubmission::find($submission->id)->data);
        $this->assertSame('Hafen B', $data['ort']);
        $this->assertArrayNotHasKey('ort_theorieort', $data);
        $this->assertArrayNotHasKey('ort_theorieort_adresse', $data);
    }
}
