<?php

namespace Tests\Feature;

use App\Jobs\Concerns\TenantAware;
use App\Jobs\MassMailJob;
use App\Jobs\SendAnnouncementMails;
use App\Models\PlatformAnnouncement;
use App\Models\TenantAnnouncement;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\AccountInvitation;
use App\Notifications\AnnouncementPublished;
use App\Notifications\LearningReminder;
use App\Notifications\PlatformAnnouncementPublished;
use App\Notifications\TenantInvitationNotification;
use App\Services\ParticipantInviter;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sammel-Mails als Queue-Jobs: der Mandantenkontext (TenantContext und
 * RLS-GUC app.current_tenant_id) kommt aus dem Dispatch, nicht aus dem
 * Zustand des Prozesses, der den Job ausführt -- geprüft mit dem echten
 * database-Treiber (Job in der Tabelle jobs, Ausführung per queue:work).
 */
class QueuedMassMailTest extends TestCase
{
    use InteractsWithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
        $this->onAdmin(fn () => DB::table('jobs')->delete());
        TenantProbeJob::$seen = null;
    }

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            DB::table('jobs')->delete();
            DB::table('failed_jobs')->where('payload', 'like', '%SendAnnouncementMails%')->delete();
        });
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    private function work(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default', '--sleep' => 0])->assertSuccessful();
    }

    private function makeJobsAvailable(): void
    {
        $this->onAdmin(fn () => DB::table('jobs')->update(['available_at' => now()->timestamp - 1]));
    }

    public function test_the_job_runs_in_the_tenant_it_was_dispatched_from_not_in_the_current_one(): void
    {
        $a = $this->createTestTenant('Queue A');
        $b = $this->createTestTenant('Queue B');
        $learnerA = $this->createTenantUser($a, 'learner');
        $this->createTenantUser($b, 'learner');

        // Dispatch im Request von Schule A ...
        $this->asTenant($a, fn () => TenantProbeJob::dispatch());
        $this->assertSame(1, $this->onAdmin(fn () => DB::table('jobs')->count()));

        // ... ausgeführt von einem Prozess, der gerade im Kontext von B steht.
        app(TenantContext::class)->set($b);
        $this->work();

        $this->assertNotNull(TenantProbeJob::$seen, 'Job lief nicht');
        $this->assertSame($a->id, TenantProbeJob::$seen['guc']);
        $this->assertSame($a->id, TenantProbeJob::$seen['context']);
        $this->assertSame($a->id, TenantProbeJob::$seen['current_tenant']);
        // Rohabfrage ohne Eloquent-Scope: nur RLS filtert -- sichtbar sind nur A-Mitglieder.
        $this->assertSame([$learnerA->id], TenantProbeJob::$seen['visible_users']);

        // Danach wieder der vorherige Zustand (B), nichts von A bleibt hängen.
        $this->assertSame($b->id, app(TenantContext::class)->id());
        $this->assertSame($b->id, DB::selectOne("SELECT current_setting('app.current_tenant_id', true) AS v")->v);
        $this->assertSame(0, $this->onAdmin(fn () => DB::table('jobs')->count()));
    }

    public function test_without_a_previous_context_the_worker_falls_back_to_the_nil_tenant(): void
    {
        $a = $this->createTestTenant('Queue A');
        $this->createTenantUser($a, 'learner');
        $this->asTenant($a, fn () => TenantProbeJob::dispatch());

        app(TenantContext::class)->clear();
        $this->work();

        $this->assertSame($a->id, TenantProbeJob::$seen['guc']);
        $this->assertFalse(app(TenantContext::class)->has());
        $this->assertSame('00000000-0000-0000-0000-000000000000', DB::selectOne("SELECT current_setting('app.current_tenant_id', true) AS v")->v);
        $this->assertFalse(app()->bound('currentTenant'));
    }

    public function test_with_the_sync_driver_the_request_keeps_its_own_context_after_the_job(): void
    {
        config(['queue.default' => 'sync']);
        $a = $this->createTestTenant('Queue A');
        $user = $this->createTenantUser($a, 'admin');
        DB::statement("SET app.current_user_id = '{$user->id}'");

        $this->asTenant($a, function () use ($a, $user) {
            TenantProbeJob::dispatch();
            $this->assertSame($a->id, TenantProbeJob::$seen['guc']);
            // Danach läuft der Request mit seinem Mandanten und seiner Nutzer-ID weiter.
            $this->assertSame($a->id, app(TenantContext::class)->id());
            $this->assertSame($a->id, DB::selectOne("SELECT current_setting('app.current_tenant_id', true) AS v")->v);
            $this->assertSame($user->id, DB::selectOne("SELECT current_setting('app.current_user_id', true) AS v")->v);
        });
        DB::statement('RESET app.current_user_id');
    }

    public function test_announcement_mails_are_queued_and_sent_by_the_worker_to_the_schools_learners_only(): void
    {
        Notification::fake();
        $a = $this->createTestTenant('Queue A');
        $b = $this->createTestTenant('Queue B');
        $admin = $this->createTenantUser($a, 'admin');
        $learners = collect(range(1, 3))->map(fn () => $this->createTenantUser($a, 'learner'));
        $other = $this->createTenantUser($b, 'learner');
        $course = $this->existingCourse('SRC');
        $learners->each(fn ($l) => $this->grantEntitlement($a, $l, $course));
        $this->grantEntitlement($b, $other, $course);

        $this->actingAsInTenant($admin, $a)->post('/admin/announcements', [
            'title' => 'Hafenfest', 'body' => 'Samstag ab 10 Uhr.', 'send_mail' => '1',
        ])->assertSessionHas('status', 'Mitteilung veröffentlicht und wird an 3 Personen verschickt.');

        Notification::assertNothingSent();
        $this->assertSame(1, $this->onAdmin(fn () => DB::table('jobs')->count()));

        app(TenantContext::class)->set($b);
        $this->work();

        $learners->each(fn ($l) => Notification::assertSentToTimes($l, AnnouncementPublished::class, 1));
        Notification::assertNotSentTo($other, AnnouncementPublished::class);
        app(TenantContext::class)->clear();
        $announcement = $this->onAdmin(fn () => TenantAnnouncement::where('tenant_id', $a->id)->firstOrFail());
        $this->assertSame(3, $announcement->emailed_count);
        $this->assertNotNull($announcement->emailed_at);
    }

    public function test_failed_mails_are_retried_without_resending_and_finally_counted(): void
    {
        Cache::forget(MassMailJob::FAILED_COUNTER_KEY);
        $a = $this->createTestTenant('Queue A');
        $ok = $this->createTenantUser($a, 'learner');
        $broken = $this->createTenantUser($a, 'learner');
        $announcement = $this->asTenant($a, fn () => TenantAnnouncement::create(['title' => 'T', 'body' => 'B', 'created_by_user_id' => $ok->id]));

        $sent = [];
        $failBroken = true;
        Notification::shouldReceive('send')->andReturnUsing(function ($notifiables) use (&$sent, &$failBroken, $broken) {
            $user = is_iterable($notifiables) ? collect($notifiables)->first() : $notifiables;
            if ($user->id === $broken->id && $failBroken) {
                throw new \RuntimeException('SMTP down');
            }
            $sent[] = $user->id;
        });

        $this->asTenant($a, fn () => SendAnnouncementMails::dispatch($announcement->id, [$ok->id, $broken->id]));

        // 1. Versuch: eine Mail geht raus, die andere scheitert -> Job zurück in die Queue (Backoff 60 s).
        $this->work();
        $this->assertSame([$ok->id], $sent);
        $job = $this->onAdmin(fn () => DB::table('jobs')->first());
        $this->assertSame(1, (int) $job->attempts);
        $this->assertGreaterThan(now()->timestamp + 30, (int) $job->available_at);

        // 2. Versuch: nur die fehlgeschlagene Mail wird erneut versucht, die zugestellte nicht doppelt.
        $failBroken = false;
        $this->makeJobsAvailable();
        $this->work();
        $this->assertSame([$ok->id, $broken->id], $sent);
        $this->assertSame(0, $this->onAdmin(fn () => DB::table('jobs')->count()));
        $this->assertSame(2, $this->onAdmin(fn () => TenantAnnouncement::find($announcement->id)->emailed_count));
        $this->assertNull(Cache::get(MassMailJob::FAILED_COUNTER_KEY));

        // Dauerhaft kaputt: nach dem 3. Versuch failed() -> Zähler und failed_jobs.
        $failBroken = true;
        $this->asTenant($a, fn () => SendAnnouncementMails::dispatch($announcement->id, [$broken->id]));
        foreach (range(1, 3) as $attempt) {
            $this->makeJobsAvailable();
            $this->work();
        }
        $this->assertSame(0, $this->onAdmin(fn () => DB::table('jobs')->count()));
        $this->assertSame(1, (int) Cache::get(MassMailJob::FAILED_COUNTER_KEY));
        $this->assertTrue($this->onAdmin(fn () => DB::table('failed_jobs')->where('payload', 'like', '%SendAnnouncementMails%')->exists()));
    }

    public function test_reminding_many_inactive_learners_is_queued_and_sent_by_the_worker(): void
    {
        Notification::fake();
        $a = $this->createTestTenant('Queue A');
        $admin = $this->createTenantUser($a, 'admin');
        $course = $this->existingCourse('SRC');
        $learners = collect(range(1, 11))->map(fn () => $this->createTenantUser($a, 'learner'));
        $learners->each(fn ($l) => $this->grantEntitlement($a, $l, $course));

        $this->actingAsInTenant($admin, $a)->post('/admin/inactive/remind', ['days' => 14])
            ->assertSessionHas('status', '11 Erinnerungen werden jetzt verschickt.');
        Notification::assertNothingSent();

        app(TenantContext::class)->clear();
        $this->work();

        $learners->each(fn ($l) => Notification::assertSentToTimes($l, LearningReminder::class, 1));
        $this->assertSame(11, $this->onAdmin(fn () => TenantUser::where('tenant_id', $a->id)->where('role', 'learner')->whereNotNull('reminded_at')->count()));
    }

    public function test_bulk_invitation_mails_are_queued_and_sent_by_the_worker(): void
    {
        Notification::fake();
        $a = $this->createTestTenant('Queue A');
        $owner = $this->createTenantUser($a, 'owner');
        $existing = $this->createTenantUser($this->createTestTenant('Queue B'), 'learner');
        $new = 'queue-bulk-'.uniqid().'@example.test';
        RateLimiter::clear(ParticipantInviter::dailyKey($a));

        $this->actingAsInTenant($owner, $a)->post('/admin/participants/bulk-invite', ['emails' => $new.'
'.$existing->email])
            ->assertSessionHas('status', '2 Personen eingeladen. Die 2 Einladungsmails werden verschickt.');
        Notification::assertNothingSent();
        $newUser = $this->onAdmin(fn () => User::where('email', $new)->firstOrFail());
        $this->createdUserIds[] = $newUser->id;

        $this->work();

        Notification::assertSentTo($newUser, AccountInvitation::class);
        Notification::assertSentOnDemand(TenantInvitationNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === $existing->email);
    }

    public function test_platform_announcement_mails_are_queued_and_sent_without_a_tenant(): void
    {
        Notification::fake();
        $superadmin = $this->createSuperAdmin();
        $a = $this->createTestTenant('Queue A');
        $b = $this->createTestTenant('Queue B');
        $ownerA = $this->createTenantUser($a, 'owner');
        $adminB = $this->createTenantUser($b, 'admin');

        $this->actingAs($superadmin)->post('/superadmin/announcements', [
            'title' => 'Wartung', 'body' => 'Sonntag 6 bis 8 Uhr.', 'target' => 'selected', 'tenant_ids' => [$a->id, $b->id], 'audience' => 'team', 'send_mail' => '1',
        ])->assertSessionHas('status', 'Mitteilung veröffentlicht und wird an 2 Personen verschickt.');
        Notification::assertNothingSent();

        // Ein Prozess im Kontext einer Schule: die Plattform-Mitteilung läuft trotzdem ohne Mandant.
        app(TenantContext::class)->set($a);
        $this->work();

        Notification::assertSentToTimes($ownerA, PlatformAnnouncementPublished::class, 1);
        Notification::assertSentToTimes($adminB, PlatformAnnouncementPublished::class, 1);
        $this->assertSame(2, $this->onAdmin(fn () => PlatformAnnouncement::latest('created_at')->firstOrFail()->emailed_count));
        $this->onAdmin(fn () => PlatformAnnouncement::query()->delete());
    }

    public function test_mass_mail_jobs_retry_three_times_with_backoff(): void
    {
        $job = new SendAnnouncementMails('x', []);
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300, 900], $job->backoff);
        $this->assertInstanceOf(ShouldQueue::class, $job);
    }
}

/** Test-Job: hält fest, in welchem Mandantenkontext er läuft. */
class TenantProbeJob implements ShouldQueue
{
    use Dispatchable, Queueable, TenantAware;

    /** @var array<string, mixed>|null */
    public static ?array $seen = null;

    public function __construct()
    {
        $this->rememberTenant();
    }

    public function handle(TenantContext $context): void
    {
        self::$seen = [
            'guc' => DB::selectOne("SELECT current_setting('app.current_tenant_id', true) AS v")->v,
            'context' => $context->id(),
            'current_tenant' => app()->bound('currentTenant') ? app('currentTenant')->id : null,
            'visible_users' => DB::table('tenant_user')->pluck('user_id')->all(),
        ];
    }
}
