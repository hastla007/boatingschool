<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\ExamRuleSet;
use App\Models\InstructorCourse;
use App\Models\NavigationQuestion;
use App\Models\NavigationTask;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\ParticipantInviter;
use App\Support\NavigationAnswer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Regressionstests zu Review-Runde 6 (Fachlogik und Sicherheit der Admin-Werkzeuge). */
class ReviewRoundSixFixesTest extends TestCase
{
    use InteractsWithTenants;

    private array $courseIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        $this->onAdmin(fn () => CourseDefinition::withoutGlobalScopes()->whereIn('id', $this->courseIds)->delete());
        parent::tearDown();
    }

    public function test_navigation_editor_keeps_copies_in_step_when_a_question_is_removed(): void
    {
        $superadmin = $this->createSuperAdmin();
        [$task, $copy] = $this->onAdmin(function () {
            $make = function (string $name) {
                $course = CourseDefinition::withoutGlobalScopes()->create(['tenant_id' => null, 'code' => 'TEST-NAV-'.uniqid(), 'name' => $name, 'course_type' => 'full', 'status' => 'published']);
                $this->courseIds[] = $course->id;
                $task = NavigationTask::create(['course_id' => $course->id, 'task_number' => 7, 'scenario_text' => 'S', 'sort_order' => 1]);
                foreach (['A', 'B', 'C'] as $i => $x) {
                    NavigationQuestion::create(['navigation_task_id' => $task->id, 'question_number' => $i + 1, 'question_text' => "Q{$x}", 'answer_text' => "A{$x}", 'sort_order' => $i + 1]);
                }

                return $task;
            };

            return [$make('Quelle'), $make('Kombi')];
        });
        $questions = $this->onAdmin(fn () => $task->questions()->orderBy('question_number')->get());

        $this->actingAs($superadmin)->patch("/superadmin/navigation-tasks/{$task->id}", [
            'scenario_text' => 'S', 'apply_to_copies' => '1',
            'questions' => [
                ['id' => $questions[0]->id, 'question_text' => 'QA', 'answer_text' => 'AA', 'delete' => '1'],
                ['id' => $questions[1]->id, 'question_text' => 'QB', 'answer_text' => 'AB'],
                ['id' => $questions[2]->id, 'question_text' => 'QC', 'answer_text' => 'AC'],
            ],
        ])->assertSessionHasNoErrors();

        foreach ([$task, $copy] as $target) {
            $rows = $this->onAdmin(fn () => NavigationQuestion::where('navigation_task_id', $target->id)->orderBy('question_number')->get());
            $this->assertSame(['QB', 'QC'], $rows->pluck('question_text')->all());
            $this->assertSame(['AB', 'AC'], $rows->pluck('answer_text')->all());
        }
    }

    public function test_exam_rule_form_shows_the_stored_quota_exactly(): void
    {
        $superadmin = $this->createSuperAdmin();
        $course = $this->existingCourse('SRC');
        $html = $this->actingAs($superadmin)->get("/superadmin/courses/{$course->id}/exam-rules")->assertOk()->getContent();
        preg_match('/name="min_correct"[^>]*value="(\d+)"/', $html, $min);
        preg_match('/name="of"[^>]*value="(\d+)"/', $html, $of);
        $ratio = $this->onAdmin(fn () => $course->activeExamRuleSet()->passing_rule['min_correct_ratio']);
        $this->assertEqualsWithDelta($ratio, $min[1] / $of[1], 1e-9, 'unverändertes Speichern darf die Quote nicht ändern');

        $this->assertSame([19, 24], (new ExamRuleSet(['passing_rule' => ['min_correct_ratio' => 19 / 24]]))->passingFraction(10));
        $this->assertSame([15, 20], (new ExamRuleSet(['passing_rule' => ['min_correct_ratio' => 0.75]]))->passingFraction(20));
    }

    public function test_voided_course_codes_are_not_used_to_unlock_a_course(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->onAdmin(fn () => Coupon::create(['code' => 'VOID'.strtoupper(substr(uniqid(), -10)), 'course_id' => $course->id, 'tenant_id' => $tenant->id, 'voided_at' => now()]));

        $this->actingAsInTenant($owner, $tenant)->post(route('admin.entitlements.store'), ['user_id' => $learner->id, 'course_id' => $course->id])
            ->assertSessionHasErrors('course_id');
        $this->assertFalse($this->onAdmin(fn () => Entitlement::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->exists()));
    }

    public function test_navigation_answers_need_the_right_number_of_values_and_parse_decimals(): void
    {
        $this->assertFalse(NavigationAnswer::evaluate('rwK = 079°', '10 20 30 40 50 60 70 80 90')['correct']);
        $this->assertFalse(NavigationAnswer::evaluate('rwK = 079°', '074/076')['correct']);
        $this->assertFalse(NavigationAnswer::evaluate('rwK = 005°', '365')['correct']);
        $this->assertTrue(NavigationAnswer::evaluate('rwK = 079°', '078')['correct']);
        $this->assertTrue(NavigationAnswer::evaluate('BV = 103° - 0,8 sm', '103° 0.80 sm')['correct']);
        $this->assertTrue(NavigationAnswer::evaluate('FüG = 5,25 kn', '5.25 kn')['correct']);
        $this->assertTrue(NavigationAnswer::evaluate('23:58 Uhr', '00:01')['correct']);
        $this->assertTrue(NavigationAnswer::evaluate("54° 03,5' N 008° 06,9' E", "N 54° 03,5' E 008° 06,9'")['correct']);
    }

    public function test_bulk_renewal_skips_entitlements_changed_since_the_preview(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $entitlement = $this->grantEntitlement($tenant, $learner, $course);
        $until = now()->addDays(10)->startOfSecond();
        $this->onAdmin(function () use ($entitlement, $until, $tenant, $course) {
            Entitlement::whereKey($entitlement->id)->update(['valid_from' => now()->subYear(), 'valid_until' => $until]);
            foreach (range(1, 2) as $i) {
                Coupon::create(['code' => 'REN'.$i.strtoupper(substr(uniqid(), -10)), 'course_id' => $course->id, 'tenant_id' => $tenant->id, 'is_renewal' => true]);
            }
        });
        $payload = ['course_id' => $course->id, 'entitlement_ids' => [$entitlement->id], 'seen' => [$entitlement->id => $until->timestamp]];

        $this->actingAsInTenant($owner, $tenant)->post('/admin/renewals', $payload)->assertSessionHas('status', 'Ein Zugang verlängert.');
        // Doppelklick: dieselbe Vorschau noch einmal abgeschickt.
        $this->actingAsInTenant($owner, $tenant)->post('/admin/renewals', $payload)
            ->assertSessionHas('status', '0 Zugänge verlängert. 1 übersprungen: inzwischen schon verlängert oder geändert.');
        $this->assertSame(1, $this->onAdmin(fn () => Coupon::where('tenant_id', $tenant->id)->where('is_renewal', true)->whereNotNull('redeemed_at')->count()));
    }

    public function test_instructor_limited_to_courses_sees_only_their_learners_on_the_dashboard(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $src = $this->existingCourse('SRC');
        $srcLearner = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Sichtbar Src']);
        $ubiLearner = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Fremd Ubi Person']);
        $this->grantEntitlement($tenant, $srcLearner, $src);
        $this->grantEntitlement($tenant, $ubiLearner, $this->existingCourse('UBI'));
        $this->onAdmin(fn () => InstructorCourse::create(['tenant_id' => $tenant->id, 'user_id' => $instructor->id, 'course_id' => $src->id]));

        $response = $this->actingAsInTenant($instructor, $tenant)->get('/admin')->assertOk();
        $users = collect($response->viewData('recentBookings'))->pluck('user')->all();
        $this->assertContains('Sichtbar Src', $users);
        $this->assertNotContains('Fremd Ubi Person', $users);
        $this->assertSame(1, $response->viewData('activeEntitlements'));
        $this->assertCount(0, $response->viewData('recentActivity'));
    }

    public function test_participant_export_neutralises_spreadsheet_formulas(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $this->createTenantUser($tenant, 'learner', ['display_name' => '=HYPERLINK("http://evil.example","x")', 'first_name' => '=1+1', 'last_name' => '@SUM(A1)']);

        $csv = $this->actingAsInTenant($owner, $tenant)->get('/admin/participants/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=1+1;", $csv);
        $this->assertStringContainsString("'@SUM(A1)", $csv);
        $this->assertDoesNotMatchRegularExpression('/(^|;|")[=@]/m', $csv);
    }

    public function test_bulk_invite_skips_open_invitations_and_is_for_owners_and_admins_only(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $other = $this->createTenantUser($this->createTestTenant(), 'learner');
        RateLimiter::clear(ParticipantInviter::dailyKey($tenant));

        $this->actingAsInTenant($instructor, $tenant)->post('/admin/participants/bulk-invite', ['emails' => $other->email])->assertForbidden();
        $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/bulk-invite', ['emails' => $other->email])
            ->assertSessionHas('status', 'Eine Person eingeladen.');
        $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/bulk-invite', ['emails' => $other->email."\nName ".str_repeat('x', 200).' <lang@example.test>'])
            ->assertSessionHas('status', fn ($s) => str_starts_with($s, '0 Personen eingeladen.') && str_contains($s, 'heute schon eingeladen') && str_contains($s, 'Name zu lang'));
    }

    public function test_failed_reminder_mail_does_not_start_the_cooldown(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $learner, $this->existingCourse('SRC'));
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('SMTP down'));

        $this->actingAsInTenant($owner, $tenant)->post('/admin/inactive/remind', ['user_id' => $learner->id, 'days' => 14])
            ->assertSessionHas('status', fn ($s) => str_contains($s, '0 Erinnerungen verschickt.') && str_contains($s, '1 konnten nicht verschickt werden'));
        $this->assertNull($this->onAdmin(fn () => TenantUser::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->value('reminded_at')));
    }

    public function test_ending_the_support_view_keeps_the_persons_other_logins(): void
    {
        $central = 'http://'.config('app.central_domain');
        $tenant = $this->createTestTenant('Ansicht Schule');
        $slug = strtolower($tenant->slug);
        $this->onAdmin(fn () => Tenant::where('id', $tenant->id)->update(['slug' => $slug]));
        $superadmin = $this->createSuperAdmin();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->onAdmin(fn () => User::whereKey($learner->id)->update(['remember_token' => 'ORIGINALTOKEN123']));

        $link = $this->actingAs($superadmin)->post("{$central}/superadmin/users/{$learner->id}/impersonate/{$tenant->id}")->assertOk()->viewData('url');
        auth()->logout();
        $this->flushSession();
        $this->get($link)->assertRedirect();
        $this->post("{$central}/{$slug}/logout")->assertOk()->assertViewIs('impersonation.ended');

        $this->assertSame('ORIGINALTOKEN123', $this->onAdmin(fn () => User::find($learner->id))->remember_token);
        $this->assertTrue($this->onAdmin(fn () => AuditLog::where('action', 'superadmin.impersonate.stop')->where('entity_id', $learner->id)->exists()));
    }

    public function test_central_remember_me_does_not_log_into_a_school_path(): void
    {
        $central = 'http://'.config('app.central_domain');
        $tenant = $this->createTestTenant('Pfad Schule');
        $slug = strtolower($tenant->slug);
        $this->onAdmin(fn () => Tenant::where('id', $tenant->id)->update(['slug' => $slug]));
        $learner = $this->createTenantUser($tenant, 'learner', ['password' => Hash::make('geheim123!x')]);

        $login = $this->post("{$central}/login", ['email' => $learner->email, 'password' => 'geheim123!x', 'remember' => '1']);
        $name = Auth::guard('web')->getRecallerName();
        $cookie = $login->getCookie($name, true);
        $this->assertNotNull($cookie);

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->withCookie($name, $cookie->getValue())->get("{$central}/{$slug}/dashboard")->assertRedirect();
        $this->assertGuest();
    }

    public function test_two_factor_setup_needs_the_password(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');

        $this->actingAsInTenant($admin, $tenant)->post('/two-factor/enable')->assertSessionHasErrors('password');
        $this->assertNull($this->onAdmin(fn () => User::find($admin->id))->two_factor_secret);
        $this->actingAsInTenant($admin, $tenant)->post('/two-factor/enable', ['password' => 'password'])->assertRedirect(route('two-factor.show'));
        $this->assertNotNull($this->onAdmin(fn () => User::find($admin->id))->two_factor_secret);
    }

    public function test_platform_legal_pages_are_reachable_on_the_central_domain(): void
    {
        $central = 'http://'.config('app.central_domain');
        $previous = $this->onAdmin(fn () => PlatformSetting::current()->operator_info);
        $this->onAdmin(fn () => PlatformSetting::current()->update(['operator_info' => "Plattform Betreiber GmbH\nHafenweg 1"]));
        $this->beforeApplicationDestroyed(fn () => $this->onAdmin(fn () => PlatformSetting::current()->update(['operator_info' => $previous])));

        $this->get("{$central}/impressum")->assertOk()->assertSee('Plattform Betreiber GmbH')->assertDontSee('Bitte wählen Sie Ihre Bootsschule');
        $this->get("{$central}/datenschutz")->assertOk()->assertDontSee('Bitte wählen Sie Ihre Bootsschule');
    }

    public function test_single_renewal_double_click_uses_only_one_code(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $entitlement = $this->grantEntitlement($tenant, $learner, $course);
        $until = now()->addDays(10)->startOfSecond();
        $this->onAdmin(function () use ($entitlement, $until, $tenant, $course) {
            Entitlement::whereKey($entitlement->id)->update(['valid_from' => now()->subYear(), 'valid_until' => $until]);
            foreach (range(1, 2) as $i) {
                Coupon::create(['code' => 'ONE'.$i.strtoupper(substr(uniqid(), -10)), 'course_id' => $course->id, 'tenant_id' => $tenant->id, 'is_renewal' => true]);
            }
        });
        $payload = ['action' => 'extend', 'seen_valid_until' => $until->timestamp];

        $this->actingAsInTenant($owner, $tenant)->patch("/admin/entitlements/{$entitlement->id}", $payload)->assertSessionHas('status', fn ($s) => str_starts_with($s, 'Zugang verlängert'));
        $this->actingAsInTenant($owner, $tenant)->patch("/admin/entitlements/{$entitlement->id}", $payload)
            ->assertSessionHas('status', 'Dieser Zugang wurde inzwischen schon verlängert oder geändert; es wurde kein weiterer Code verbraucht.');

        $this->assertSame(1, $this->onAdmin(fn () => Coupon::where('tenant_id', $tenant->id)->where('is_renewal', true)->whereNotNull('redeemed_at')->count()));
        $this->assertSame(1, $this->onAdmin(fn () => AuditLog::where('action', 'entitlement.extend')->where('entity_id', $entitlement->id)->count()));
    }

    public function test_participant_page_sends_the_shown_end_date_with_the_renewal(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $entitlement = $this->grantEntitlement($tenant, $learner, $this->existingCourse('SRC'));
        $until = now()->addDays(10)->startOfSecond();
        $this->onAdmin(fn () => Entitlement::whereKey($entitlement->id)->update(['valid_from' => now()->subYear(), 'valid_until' => $until]));

        $this->actingAsInTenant($owner, $tenant)->get("/admin/participants/{$learner->id}")->assertOk()
            ->assertSee('name="seen_valid_until" value="'.$until->timestamp.'"', false);
    }
}
