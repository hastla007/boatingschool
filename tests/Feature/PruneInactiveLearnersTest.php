<?php

namespace Tests\Feature;

use App\Api\DeviceSessions;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\InactiveAccountDeletionWarning;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Löschfrist für inaktive Lernende (app:prune-inactive-learners):
 * Vorwarnung per Mail, Löschung frühestens 30 Tage danach, nie für Team
 * und Superadmins, nie bei aktivem Kurszugang.
 *
 * Die Testdatenbank ist geteilt: statt Jahre in die Zukunft zu reisen (dann
 * wären alle übrigen Konten „inaktiv“) werden die Zeitstempel des
 * Testkontos zurückdatiert; gereist wird nur über die 30-Tage-Frist.
 */
class PruneInactiveLearnersTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdSessionIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['data_retention.inactive_learners.months' => 12]);
    }

    protected function tearDown(): void
    {
        $this->onAdmin(fn () => DB::table('sessions')->whereIn('id', $this->createdSessionIds)->delete());
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    /** Lernkonto, das seit $months Monaten nichts mehr getan hat. */
    private function inactiveLearner(Tenant $tenant, string $role = 'learner', int $months = 13): User
    {
        $user = $this->createTenantUser($tenant, $role);
        $this->backdate($user, $months);

        return $user;
    }

    private function backdate(User $user, int $months): void
    {
        $this->onAdmin(function () use ($user, $months) {
            DB::table('app_user')->where('id', $user->id)->update(['created_at' => now()->subMonths($months)]);
            TenantUser::where('user_id', $user->id)->update(['created_at' => now()->subMonths($months)]);
        });
    }

    private function fresh(User $user): ?User
    {
        return $this->onAdmin(fn () => User::find($user->id));
    }

    public function test_the_deletion_is_off_unless_a_period_is_configured(): void
    {
        config(['data_retention.inactive_learners.months' => null]);
        $user = $this->inactiveLearner($this->createTestTenant());

        $this->artisan('app:prune-inactive-learners')->expectsOutputToContain('deaktiviert')->assertExitCode(0);

        $this->assertNull($this->fresh($user)->inactivity_warned_at);
        Notification::assertNothingSent();
    }

    public function test_an_inactive_learner_is_warned_first_and_deleted_thirty_days_later(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->inactiveLearner($tenant);

        $this->artisan('app:prune-inactive-learners')->assertExitCode(0);

        $this->assertNotNull($this->fresh($user)->inactivity_warned_at);
        Notification::assertSentTo($user, InactiveAccountDeletionWarning::class, function ($notification) use ($user) {
            $mail = $notification->toMail($user);

            return str_contains($mail->subject, 'gelöscht') && str_contains(implode(' ', $mail->introLines), 'Du hast dich seit über 12 Monaten');
        });

        // Am nächsten Tag: keine zweite Mail, noch keine Löschung.
        $this->travel(1)->days();
        $this->artisan('app:prune-inactive-learners')->assertExitCode(0);
        $this->assertNotNull($this->fresh($user));
        Notification::assertSentToTimes($user, InactiveAccountDeletionWarning::class, 1);

        // 30 Tage nach der Vorwarnung: gelöscht.
        $this->travel(30)->days();
        $this->artisan('app:prune-inactive-learners')->assertExitCode(0);
        $this->assertNull($this->fresh($user));
        $this->assertFalse($this->onAdmin(fn () => TenantUser::withoutGlobalScopes()->where('user_id', $user->id)->exists()));
    }

    public function test_the_warning_comes_thirty_days_before_the_period_ends(): void
    {
        $tenant = $this->createTestTenant();
        // 11 Monate und 10 Tage inaktiv: innerhalb der letzten 30 Tage vor Fristende.
        $user = $this->createTenantUser($tenant);
        $this->onAdmin(function () use ($user) {
            DB::table('app_user')->where('id', $user->id)->update(['created_at' => now()->subMonths(11)->subDays(10)]);
            TenantUser::where('user_id', $user->id)->update(['created_at' => now()->subMonths(11)->subDays(10)]);
        });
        $notYet = $this->inactiveLearner($tenant, months: 10);

        $this->artisan('app:prune-inactive-learners')->assertExitCode(0);

        $this->assertNotNull($this->fresh($user)->inactivity_warned_at);
        $this->assertNull($this->fresh($notYet)->inactivity_warned_at);
        Notification::assertNotSentTo($notYet, InactiveAccountDeletionWarning::class);
    }

    public function test_team_members_superadmins_and_learners_with_active_access_are_never_affected(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->inactiveLearner($tenant, 'instructor');
        $admin = $this->inactiveLearner($tenant, 'admin');
        $superadmin = $this->createSuperAdmin();
        $this->onAdmin(fn () => DB::table('app_user')->where('id', $superadmin->id)->update(['created_at' => now()->subYears(3)]));
        $withAccess = $this->inactiveLearner($tenant);
        $this->grantEntitlement($tenant, $withAccess, $this->existingCourse('SBF-SEE'));

        $this->artisan('app:prune-inactive-learners')->assertExitCode(0);
        $this->travel(40)->days();
        $this->artisan('app:prune-inactive-learners')->assertExitCode(0);

        foreach ([$instructor, $admin, $superadmin, $withAccess] as $user) {
            $this->assertNotNull($this->fresh($user));
            $this->assertNull($this->fresh($user)->inactivity_warned_at);
            Notification::assertNotSentTo($user, InactiveAccountDeletionWarning::class);
        }
    }

    public function test_recent_activity_protects_the_account(): void
    {
        $tenant = $this->createTestTenant();
        $recentLogin = $this->inactiveLearner($tenant);
        $this->onAdmin(fn () => DB::table('app_user')->where('id', $recentLogin->id)->update(['last_login_at' => now()->subMonths(2)]));
        $recentSession = $this->inactiveLearner($tenant);
        $id = (string) Str::uuid();
        $this->createdSessionIds[] = $id;
        $this->onAdmin(fn () => DB::table('sessions')->insert(['id' => $id, 'user_id' => $recentSession->id, 'payload' => base64_encode('x'), 'last_activity' => now()->subDays(3)->timestamp]));

        $this->artisan('app:prune-inactive-learners')->assertExitCode(0);

        $this->assertNull($this->fresh($recentLogin)->inactivity_warned_at);
        $this->assertNull($this->fresh($recentSession)->inactivity_warned_at);
        Notification::assertNothingSentTo([$recentLogin, $recentSession]);
    }

    public function test_logging_in_after_the_warning_keeps_the_account(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->inactiveLearner($tenant);
        $this->artisan('app:prune-inactive-learners')->assertExitCode(0);
        $this->assertNotNull($this->fresh($user)->inactivity_warned_at);

        $this->travel(10)->days();
        $this->withSession(['dev_tenant_slug' => $tenant->slug])
            ->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->assertNotNull($this->fresh($user)->last_login_at);
        $this->assertNull($this->fresh($user)->inactivity_warned_at);

        $this->travel(30)->days();
        $this->artisan('app:prune-inactive-learners')->assertExitCode(0);
        $this->assertNotNull($this->fresh($user));
        Notification::assertSentToTimes($user, InactiveAccountDeletionWarning::class, 1);
    }

    public function test_a_new_course_access_lifts_the_warning(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->inactiveLearner($tenant);
        $this->artisan('app:prune-inactive-learners')->assertExitCode(0);
        $this->grantEntitlement($tenant, $user, $this->existingCourse('SBF-SEE'));

        $this->travel(31)->days();
        $this->artisan('app:prune-inactive-learners')->assertExitCode(0);

        $this->assertNotNull($this->fresh($user));
        $this->assertNull($this->fresh($user)->inactivity_warned_at);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $user = $this->inactiveLearner($this->createTestTenant());

        $this->artisan('app:prune-inactive-learners --dry-run')->expectsOutputToContain('würden vorgewarnt')->assertExitCode(0);

        $this->assertNull($this->fresh($user)->inactivity_warned_at);
        Notification::assertNothingSent();
    }

    public function test_an_app_login_counts_as_login(): void
    {
        $user = $this->createTenantUser($this->createTestTenant());

        $this->onAdmin(fn () => app(DeviceSessions::class)->issue($user, 'Testgerät', 'ios', '1.0'));

        $this->assertNotNull($this->fresh($user)->last_login_at);
    }

    public function test_the_data_export_contains_last_login_and_warning(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->inactiveLearner($tenant);
        $this->artisan('app:prune-inactive-learners')->assertExitCode(0);
        $this->onAdmin(fn () => DB::table('app_user')->where('id', $user->id)->update(['last_login_at' => now()->subMonths(13)]));

        $data = json_decode($this->actingAsInTenant($this->fresh($user), $tenant)->get('/profile/export-data')->assertOk()->getContent(), true);

        $this->assertArrayHasKey('last_login_at', $data['profile']);
        $this->assertArrayHasKey('inactivity_deletion_warned_at', $data['profile']);
        $this->assertNotNull($data['profile']['inactivity_deletion_warned_at']);
    }

    public function test_the_command_runs_daily_on_one_server_without_overlapping(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'app:prune-inactive-learners'));

        $this->assertNotNull($event);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
    }
}
