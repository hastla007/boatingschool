<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Models\TenantBranding;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Bootsschul-Admin: Sammel-Verlängerung, 2FA-Pflicht fürs Team, Ausbilder nur für eigene Kurse. */
class SchoolAdminTeamControlsTest extends TestCase
{
    use InteractsWithTenants;

    private array $templateIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        $this->onAdmin(fn () => PraxisFormTemplate::whereIn('id', $this->templateIds)->delete());
        parent::tearDown();
    }

    private function renewalCodes($tenant, $course, int $count): void
    {
        $this->onAdmin(function () use ($tenant, $course, $count) {
            foreach (range(1, $count) as $i) {
                Coupon::create(['code' => 'REN-'.Str::upper(Str::random(10)), 'tenant_id' => $tenant->id, 'course_id' => $course->id, 'is_renewal' => true]);
            }
        });
    }

    public function test_bulk_renewal_extends_selected_access_and_stops_when_codes_run_out(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $src = $this->existingCourse('SRC');
        $days = $this->onAdmin(fn () => TenantBranding::find($tenant->id)->course_validity_days);
        $ends = [now()->addDays(5), now()->addDays(10), now()->subDays(3)];
        $entitlements = collect($ends)->map(function ($end) use ($tenant, $src) {
            $learner = $this->createTenantUser($tenant, 'learner');
            $e = $this->grantEntitlement($tenant, $learner, $src);
            $this->onAdmin(fn () => Entitlement::where('id', $e->id)->update(['valid_from' => now()->subDays(60), 'valid_until' => $end, 'status' => $end->isPast() ? 'expired' : 'active']));

            return $e;
        });
        $farFuture = $this->grantEntitlement($tenant, $this->createTenantUser($tenant, 'learner'), $src);
        $this->onAdmin(fn () => Entitlement::where('id', $farFuture->id)->update(['valid_until' => now()->addDays(200)]));
        $this->renewalCodes($tenant, $src, 2);

        $page = $this->actingAsInTenant($admin, $tenant)->get("/admin/renewals?course_id={$src->id}&window=30")->assertOk();
        $page->assertViewHas('entitlements', fn ($list) => $list->count() === 3 && ! $list->contains('id', $farFuture->id))->assertViewHas('freeCodes', 2);

        $this->actingAsInTenant($admin, $tenant)->post('/admin/renewals', ['course_id' => $src->id, 'entitlement_ids' => $entitlements->pluck('id')->all()])
            ->assertSessionHas('status', '2 Zugänge verlängert. 1 nicht verlängert: keine freien Verlängerungscodes mehr.');

        $fresh = $this->onAdmin(fn () => Entitlement::whereIn('id', $entitlements->pluck('id'))->get()->keyBy('id'));
        // Zuerst endende zuerst: der abgelaufene (ab jetzt) und der in 5 Tagen endende.
        $expired = $fresh[$entitlements[2]->id];
        $this->assertSame('active', $expired->status);
        $this->assertTrue($expired->valid_until->isAfter(now()->addDays($days - 1)));
        $this->assertSame($ends[0]->copy()->addDays($days)->toDateString(), $fresh[$entitlements[0]->id]->valid_until->toDateString());
        $this->assertSame($ends[1]->toDateString(), $fresh[$entitlements[1]->id]->valid_until->toDateString(), 'ohne Code unverändert');

        $instructor = $this->createTenantUser($tenant, 'instructor');
        $this->actingAsInTenant($instructor, $tenant)->get('/admin/renewals')->assertForbidden();
    }

    public function test_team_two_factor_requirement(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner', ['password' => Hash::make('geheim123!')]);
        $instructor = $this->createTenantUser($tenant, 'instructor', ['password' => Hash::make('geheim123!')]);
        $learner = $this->createTenantUser($tenant, 'learner');
        $fresh = fn (User $u) => $this->onAdmin(fn () => User::findOrFail($u->id));

        // Inhaber ohne eigene 2FA kann die Pflicht nicht einschalten.
        $this->actingAsInTenant($owner, $tenant)->patch('/admin/team/two-factor-policy', ['require_team_two_factor' => '1'])->assertSessionHasErrors('two_factor');
        $this->onAdmin(fn () => User::findOrFail($owner->id)->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save());
        $this->actingAsInTenant($fresh($owner), $tenant)->patch('/admin/team/two-factor-policy', ['require_team_two_factor' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue($this->onAdmin(fn () => TenantBranding::find($tenant->id)->require_team_two_factor));

        // Ausbilder ohne 2FA: zur Einrichtung umgeleitet; Lernende nicht betroffen.
        $this->actingAsInTenant($instructor, $tenant)->get('/admin')->assertRedirect(route('two-factor.show'));
        $this->actingAsInTenant($instructor, $tenant)->get('/two-factor')->assertOk();
        $this->actingAsInTenant($learner, $tenant)->get('/dashboard')->assertOk();
        $this->actingAsInTenant($fresh($owner), $tenant)->get('/admin')->assertOk()->assertSee('ohne 2FA');

        // Unter der Pflicht lässt sich 2FA nicht selbst abschalten.
        $this->actingAsInTenant($fresh($owner), $tenant)->delete('/two-factor', ['password' => 'geheim123!'])->assertSessionHasErrors('password');
        $this->assertTrue($fresh($owner)->hasTwoFactor());

        // Nur der Inhaber schaltet um.
        $admin = $this->createTenantUser($tenant, 'admin');
        $this->onAdmin(fn () => User::findOrFail($admin->id)->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save());
        $this->actingAsInTenant($fresh($admin), $tenant)->patch('/admin/team/two-factor-policy', ['require_team_two_factor' => '0'])->assertForbidden();
    }

    public function test_instructors_with_assigned_courses_only_see_their_learners(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $src = $this->existingCourse('SRC');
        $ubi = $this->existingCourse('UBI');
        $srcLearner = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Sina SRC']);
        $ubiLearner = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Uwe UBI']);
        $this->grantEntitlement($tenant, $srcLearner, $src);
        $this->grantEntitlement($tenant, $ubiLearner, $ubi);
        $template = $this->onAdmin(function () use ($ubi) {
            $t = PraxisFormTemplate::create(['title' => 'Scope-Test '.Str::random(6), 'min_progress_percent' => 0, 'course_ids' => [$ubi->id], 'fields' => [], 'active' => true]);
            $this->templateIds[] = $t->id;

            return $t;
        });
        $ubiSubmission = $this->onAdmin(fn () => PraxisFormSubmission::create(['tenant_id' => $tenant->id, 'course_id' => $ubi->id, 'user_id' => $ubiLearner->id, 'praxis_form_template_id' => $template->id, 'data' => []]));

        $names = fn () => $this->actingAsInTenant($instructor, $tenant)->get('/admin?tab=participants')->viewData('participants')->getCollection()->map(fn ($m) => $m->user->display_name)->sort()->values()->all();
        $this->assertSame(['Sina SRC', 'Uwe UBI'], $names(), 'ohne Zuordnung: alle');

        $this->actingAsInTenant($admin, $tenant)->patch("/admin/team/{$instructor->id}/courses", ['course_ids' => [$src->id]])->assertSessionHasNoErrors();
        $this->assertSame(['Sina SRC'], $names());
        $this->actingAsInTenant($instructor, $tenant)->get("/admin/participants/{$srcLearner->id}")->assertOk();
        $this->actingAsInTenant($instructor, $tenant)->get("/admin/participants/{$ubiLearner->id}")->assertNotFound();
        $this->actingAsInTenant($instructor, $tenant)->get("/admin/praxis-form-submissions/{$ubiSubmission->id}")->assertNotFound();
        $this->actingAsInTenant($instructor, $tenant)->get('/admin/statistics')->assertViewHas('withAccess', 1);
        $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$ubiLearner->id}")->assertOk();

        // Zuordnung leeren: wieder alle.
        $this->actingAsInTenant($admin, $tenant)->patch("/admin/team/{$instructor->id}/courses", ['course_ids' => []]);
        $this->assertSame(['Sina SRC', 'Uwe UBI'], $names());
        // Nur für Ausbilder.
        $this->actingAsInTenant($admin, $tenant)->patch("/admin/team/{$srcLearner->id}/courses", ['course_ids' => [$src->id]])->assertNotFound();
    }
}
