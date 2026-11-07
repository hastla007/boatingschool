<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\ExamPaper;
use App\Models\ExamSession;
use App\Models\InstructorCourse;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Services\AccountErasureService;
use App\Services\ParticipantInviter;
use App\Support\CanonicalUrl;
use App\Support\LegalPages;
use App\Support\NavigationAnswer;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Regressionstests zu Review-Runde 7 (Rückschritte der Korrekturen aus Runde 6, Lernfunktionen, Oberfläche). */
class ReviewRoundSevenFixesTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    private function lowercaseSlugTenant(string $name): array
    {
        $tenant = $this->createTestTenant($name);
        $slug = strtolower($tenant->slug);
        $this->onAdmin(fn () => Tenant::where('id', $tenant->id)->update(['slug' => $slug]));

        return [$tenant->fresh(), $slug];
    }

    public function test_navigation_answers_accept_common_ways_of_writing_a_correct_answer(): void
    {
        $correct = [
            ['14:30 Uhr', '14:30Uhr'], ['14:30 Uhr', '14.30'], ['Um 09:37 Uhr', '09.37'],
            ['Tonne "TG15" rwP = 290° Tonne "TG17/Weser1" rwP = 345° Tonne "Accumer Ee" rwP = 232°', 'TG 15 290°, TG 17 345°, Accumer Ee 232°'],
            ['Tonne "ST" rwP = 241° Tonne "A2" rwP = 151°', 'ST 241°, A 2 151°'],
            ['Leuchttonne "4a" rwP = 170° Leuchttonne "ST" rwP = 065°', 'Tonne 4 a: 170°, ST: 065°'],
            ['rwK = 038°', '038° (MgK 035°)'], ['rwK = 038°', 'rwK 038° MgK 036°'], ['rwK = 038°', 'Kurs 1: 038°'],
            ['MgK = 229°', 'rwK 231°, Mw -2°, MgK 229°'], ['5,4 sm', '5,4 sm (in 1 h)'],
            ['BV = 103° - 0,8 sm', '103° 0.80 sm'], ['23:58 Uhr', '00:01'],
        ];
        foreach ($correct as [$model, $given]) {
            $this->assertTrue(NavigationAnswer::evaluate($model, $given)['correct'], "„{$given}“ zu „{$model}“");
        }
        foreach ([['rwK = 079°', '10 20 30 40 50 60 70 80 90'], ['rwK = 079°', '074/076'], ['rwK = 079°', 'rwK 074 rwK 078'], ['rwK = 005°', '365']] as [$model, $given]) {
            $this->assertFalse(NavigationAnswer::evaluate($model, $given)['correct'], "„{$given}“ zu „{$model}“");
        }
    }

    public function test_logout_in_support_view_on_a_school_subdomain_uses_the_interstitial_page(): void
    {
        config(['app.tenant_url_mode' => 'subdomain']);
        $central = config('app.central_domain');
        [$tenant, $slug] = $this->lowercaseSlugTenant('R7 Ansicht');
        $superadmin = $this->createSuperAdmin();
        $learner = $this->createTenantUser($tenant, 'learner');

        $link = $this->actingAs($superadmin)->post("http://{$central}/superadmin/users/{$learner->id}/impersonate/{$tenant->id}")->assertOk()->viewData('url');
        auth()->logout();
        $this->flushSession();
        $this->get($link)->assertRedirect();
        $this->post("http://{$slug}.{$central}/logout")->assertOk()->assertViewIs('impersonation.ended');
    }

    public function test_deleting_the_own_account_removes_open_invitations_of_other_schools(): void
    {
        $home = $this->createTestTenant('R7 Heimat');
        $other = $this->createTestTenant('R7 Andere');
        $learner = $this->createTenantUser($home, 'learner');
        $invitation = $this->onAdmin(fn () => TenantInvitation::create(['tenant_id' => $other->id, 'email' => strtoupper($learner->email), 'role' => 'learner']));
        $this->onAdmin(fn () => AuditLog::record($other->id, null, 'participant.invite_pending', 'tenant_invitation', $invitation->id, null, ['email' => $learner->email]));

        app(TenantContext::class)->set($home);
        app(AccountErasureService::class)->leaveTenant($learner, $home);

        $this->assertFalse($this->onAdmin(fn () => TenantInvitation::withoutGlobalScopes()->whereKey($invitation->id)->exists()));
        $this->assertNull($this->onAdmin(fn () => AuditLog::where('entity_id', $invitation->id)->first()->after_data));
        $this->assertFalse($this->onAdmin(fn () => User::whereKey($learner->id)->exists()));
    }

    public function test_single_invite_does_not_mail_the_same_person_twice_a_day_and_has_a_daily_limit(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $other = $this->createTenantUser($this->createTestTenant(), 'learner');
        RateLimiter::clear(ParticipantInviter::dailyKey($tenant));

        $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/invite', ['name' => 'Gast', 'email' => $other->email])->assertSessionHas('status', 'Einladung verschickt.');
        $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/invite', ['name' => 'Gast', 'email' => $other->email])
            ->assertSessionHas('status', 'Diese Person wurde heute schon eingeladen; die Einladung ist weiter gültig.');
        $this->assertSame(1, $this->onAdmin(fn () => TenantInvitation::where('tenant_id', $tenant->id)->count()));

        foreach (range(1, ParticipantInviter::DAILY_LIMIT) as $i) {
            RateLimiter::hit(ParticipantInviter::dailyKey($tenant), 86400);
        }
        $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/invite', ['name' => 'Neu', 'email' => 'r7-limit@example.test'])->assertSessionHasErrors('email');
        RateLimiter::clear(ParticipantInviter::dailyKey($tenant));
    }

    public function test_school_links_keep_the_local_port_in_subdomain_mode(): void
    {
        config(['app.tenant_url_mode' => 'subdomain']);
        $host = config('app.central_domain');
        [$tenant, $slug] = $this->lowercaseSlugTenant('R7 Port');
        $this->app->instance('request', Request::create("http://{$slug}.{$host}:8000/x"));
        $this->assertSame("http://{$slug}.{$host}:8000", CanonicalUrl::root($tenant));
        $this->app->instance('request', Request::create("http://{$host}:8000/x"));
        $this->assertSame("http://{$host}:8000", CanonicalUrl::root());
    }

    public function test_bulk_renewal_reads_the_preview_end_dates_from_one_field(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $entitlement = $this->grantEntitlement($tenant, $learner, $course);
        $until = now()->addDays(10)->startOfSecond();
        $this->onAdmin(function () use ($entitlement, $until, $tenant, $course) {
            Entitlement::whereKey($entitlement->id)->update(['valid_from' => now()->subYear(), 'valid_until' => $until]);
            Coupon::create(['code' => 'R7J'.strtoupper(substr(uniqid(), -10)), 'course_id' => $course->id, 'tenant_id' => $tenant->id, 'is_renewal' => true]);
        });

        $this->actingAsInTenant($owner, $tenant)->get("/admin/renewals?course_id={$course->id}")->assertOk()
            ->assertSee('name="seen_json"', false)->assertDontSee('name="seen[', false);
        $this->actingAsInTenant($owner, $tenant)->post('/admin/renewals', [
            'course_id' => $course->id, 'entitlement_ids' => [$entitlement->id], 'seen_json' => json_encode([$entitlement->id => $until->timestamp - 60]),
        ])->assertSessionHas('status', '0 Zugänge verlängert. 1 übersprungen: inzwischen schon verlängert oder geändert.');
    }

    public function test_statistics_work_for_an_instructor_without_learners(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $this->onAdmin(fn () => InstructorCourse::create(['tenant_id' => $tenant->id, 'user_id' => $instructor->id, 'course_id' => $this->existingCourse('SRC')->id]));

        $this->actingAsInTenant($instructor, $tenant)->get('/admin/statistics')->assertOk();
        $this->actingAsInTenant($instructor, $tenant)->get('/admin/inactive')->assertOk();
    }

    public function test_platform_privacy_page_does_not_use_the_school_template(): void
    {
        $this->get('http://'.config('app.central_domain').'/datenschutz')->assertOk()
            ->assertSee('Lernen bei deiner Bootsschule')->assertDontSee('im Auftrag der Bootsschule');
    }

    public function test_course_card_labels_are_readable_on_the_coloured_cards(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE-BIN');
        $this->grantEntitlement($tenant, $learner, $course);

        $html = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}")->assertOk()->getContent();
        $this->assertStringContainsString('uppercase text-white/80">Dein nächster Schritt', $html);
        $this->assertStringNotContainsString('style="color: var(--brand-secondary, #6EE7E0)">Dein', $html);
    }

    public function test_favorite_toggle_uses_the_school_path_and_only_trusts_a_204(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $html = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/learn")->assertOk()->getContent();
        $this->assertStringNotContainsString("fetch('/favorites/'", $html);
        $this->assertStringContainsString('response.status !== 204', $html);
    }

    public function test_exam_can_be_handed_in_early_and_a_running_paper_shows_continue(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);
        $paper = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->orderBy('paper_number')->firstOrFail());

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}")->assertRedirect();
        $session = $this->onAdmin(fn () => ExamSession::where('user_id', $learner->id)->where('status', 'running')->firstOrFail());
        $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}")->assertOk()
            ->assertSee(route('exam.finish', $session), false)->assertSee('Prüfung vorzeitig abgeben');
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/exam")->assertOk()->assertSee('läuft', false);

        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/finish")->assertRedirect(route('exam.result', $session));
        $this->assertSame('evaluated', $this->onAdmin(fn () => $session->fresh()->status));
    }

    public function test_forbidden_page_shows_the_german_reason(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('UBI');

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}")->assertForbidden()
            ->assertSee('Für diesen Kurs liegt kein aktives Entitlement vor.');
    }

    public function test_legal_pages_drop_an_introduction_whose_value_is_empty(): void
    {
        $text = LegalPages::fill("Die Lernplattform wird betrieben von:\n\n{betreiber}\n\nWeiter", ['betreiber' => '']);
        $this->assertStringNotContainsString('betrieben von:', $text);
        $this->assertStringContainsString('Weiter', $text);
    }

    public function test_text_on_the_secondary_colour_stays_readable(): void
    {
        $light = new TenantBranding(['secondary_color' => '#FDE047']);
        $dark = new TenantBranding(['secondary_color' => '#0B3D64']);
        $this->assertSame('#0f172a', $light->onSecondaryColor());
        $this->assertSame('#ffffff', $dark->onSecondaryColor());
    }

    public function test_navigation_placeholder_and_compact_positions(): void
    {
        $model = "53° 47,2' N 007° 29,1' E";
        $this->assertStringContainsString('N 008°', NavigationAnswer::placeholder($model));
        $this->assertSame('z. B. 079°, 6,1 sm', NavigationAnswer::placeholder('BV = 103° - 0,8 sm'));
        foreach (["N53°47,2' E007°29,1'", "53°47,2'N007°29,1'E", 'N53 47,2 E7 29,1'] as $given) {
            $this->assertTrue(NavigationAnswer::evaluate($model, $given)['correct'], $given);
        }
    }
}
