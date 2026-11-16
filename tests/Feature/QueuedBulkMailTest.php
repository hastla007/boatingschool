<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AnnouncementController;
use App\Jobs\Middleware\WithSchoolContext;
use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\TenantAnnouncement;
use App\Models\TenantUser;
use App\Notifications\AccountInvitation;
use App\Notifications\AnnouncementPublished;
use App\Notifications\CouponRedeemed;
use App\Notifications\LearningReminder;
use App\Services\ParticipantInviter;
use App\Support\TenantContext;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Audit ARC-2 / QA-D-1: Massen-Mails (Mitteilungen, Sammel-Einladung,
 * Sammel-Verlängerung, Lern-Erinnerungen) liefen synchron bzw. per defer()
 * im Request -- ein hängender SMTP-Server ließ PHP-FPM den Request nach 300 s
 * abbrechen (emailed_at nie gesetzt, erneutes Absenden = doppelte Mails).
 * Jetzt je Empfänger ein Job; im Worker setzt WithSchoolContext die Schule.
 */
class QueuedBulkMailTest extends TestCase
{
    use InteractsWithTenants;

    /** @return Collection<int, SendQueuedNotifications> */
    private function pushed(string $notificationClass)
    {
        return collect(Queue::pushedJobs()[SendQueuedNotifications::class] ?? [])->pluck('job')
            ->filter(fn ($job) => $job->notification instanceof $notificationClass)->values();
    }

    public function test_announcement_mails_are_queued_with_the_school_and_claimed_before_sending(): void
    {
        Queue::fake();
        $tenant = $this->createTestTenant('Queue Schule');
        $admin = $this->createTenantUser($tenant, 'admin');
        $src = $this->existingCourse('SRC');
        $learners = collect(range(1, 3))->map(fn () => $this->createTenantUser($tenant, 'learner'));
        $learners->each(fn ($l) => $this->grantEntitlement($tenant, $l, $src));

        $this->actingAsInTenant($admin, $tenant)->post('/admin/announcements', [
            'title' => 'Hafenfest', 'body' => 'Am Samstag.', 'send_mail' => '1',
        ])->assertSessionHas('status', 'Mitteilung veröffentlicht, die Mails an 3 Personen werden im Hintergrund verschickt.');

        $jobs = $this->pushed(AnnouncementPublished::class);
        $this->assertCount(3, $jobs);
        foreach ($jobs as $job) {
            $this->assertTrue($job->afterCommit);
            $this->assertSame($tenant->id, $job->notification->schoolId);
            $this->assertEquals([new WithSchoolContext($tenant->id)], $job->middleware);
        }
        $announcement = $this->onAdmin(fn () => TenantAnnouncement::where('tenant_id', $tenant->id)->first());
        $this->assertNotNull($announcement->emailed_at);
        $this->assertSame(3, $announcement->emailed_count);
    }

    public function test_the_worker_renders_each_queued_mail_with_its_own_schools_branding(): void
    {
        config(['queue.default' => 'database']);
        $src = $this->existingCourse('SRC');
        $schools = [];
        foreach (['Möwe Nord', 'Kranich Süd'] as $name) {
            $tenant = $this->createTestTenant($name);
            DB::connection('pgsql_admin')->table('tenant_branding')->where('tenant_id', $tenant->id)->update([
                'support_email' => Str::slug($name).'@schule.test', 'support_email_verified_at' => now(),
            ]);
            $admin = $this->createTenantUser($tenant, 'admin');
            $learner = $this->createTenantUser($tenant, 'learner');
            $this->grantEntitlement($tenant, $learner, $src);
            $this->actingAsInTenant($admin, $tenant)->post('/admin/announcements', [
                'title' => "Neues von {$name}", 'body' => 'Text.', 'send_mail' => '1',
            ])->assertSessionHasNoErrors();
            $schools[$learner->email] = $tenant;
        }
        $this->assertSame(0, app('mailer')->getSymfonyTransport()->messages()->count(), 'nichts im Request verschickt');

        // Wie im Worker: kein Request-Kontext.
        app(TenantContext::class)->clear();
        app()->forgetInstance('currentTenant');
        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--tries' => 1, '--memory' => 4096]));

        $messages = app('mailer')->getSymfonyTransport()->messages()
            ->filter(fn ($m) => isset($schools[$m->getOriginalMessage()->getTo()[0]->getAddress()]));
        $this->assertCount(2, $messages);
        foreach ($messages as $sent) {
            $message = $sent->getOriginalMessage();
            $tenant = $schools[$message->getTo()[0]->getAddress()];
            $this->assertSame($tenant->name, $message->getFrom()[0]->getName());
            $this->assertSame(Str::slug($tenant->name).'@schule.test', $message->getReplyTo()[0]->getAddress());
            $html = $message->getHtmlBody();
            $this->assertSame('Neues von '.$tenant->name, $message->getSubject());
            // Unterschrift der Schule (MailSchool) statt des Plattformnamens.
            $this->assertStringContainsString('>'.e($tenant->name).'</p>', $html);
            $this->assertStringContainsString('/'.$tenant->slug.'/dashboard', $html);
        }
        $this->assertNull(app(TenantContext::class)->tenant(), 'Kontext nach dem Job zurückgesetzt');
        $this->assertSame(0, DB::connection('pgsql_admin')->table('failed_jobs')->where('payload', 'like', '%AnnouncementPublished%')->count());
    }

    public function test_announcement_message_names_the_cut_at_the_limit(): void
    {
        $this->assertSame(
            ', die Mails an 500 Personen werden im Hintergrund verschickt. Höchstens 500 Mails je Mitteilung: 12 weitere Personen wurden nicht angeschrieben.',
            AnnouncementController::mailStatus(500, 12, 500)
        );
    }

    public function test_bulk_invite_queues_encrypted_invitations(): void
    {
        Queue::fake();
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        RateLimiter::clear(ParticipantInviter::dailyKey($tenant));
        $emails = collect(range(1, 5))->map(fn ($i) => 'sammel-'.Str::random(8)."-{$i}@example.test");

        $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/bulk-invite', ['emails' => $emails->implode("\n")])
            ->assertSessionHas('status', '5 Personen eingeladen. Die Einladungsmails werden im Hintergrund verschickt.');
        $this->createdUserIds = array_merge($this->createdUserIds, $this->onAdmin(fn () => TenantUser::where('tenant_id', $tenant->id)->where('role', 'learner')->pluck('user_id')->all()));

        $jobs = $this->pushed(AccountInvitation::class);
        $this->assertCount(5, $jobs);
        $this->assertTrue($jobs->every(fn ($job) => $job->shouldBeEncrypted && $job->notification->schoolId === $tenant->id));
    }

    public function test_bulk_renewal_queues_the_access_mails(): void
    {
        Queue::fake();
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $src = $this->existingCourse('SRC');
        $entitlements = collect(range(1, 2))->map(function () use ($tenant, $src) {
            $e = $this->grantEntitlement($tenant, $this->createTenantUser($tenant, 'learner'), $src);
            $this->onAdmin(fn () => Entitlement::where('id', $e->id)->update(['valid_from' => now()->subDays(60), 'valid_until' => now()->addDays(5)]));

            return $e;
        });
        $this->onAdmin(fn () => collect(range(1, 2))->each(fn () => Coupon::create(['code' => 'REN-'.Str::upper(Str::random(10)), 'tenant_id' => $tenant->id, 'course_id' => $src->id, 'is_renewal' => true])));

        $this->actingAsInTenant($admin, $tenant)->post('/admin/renewals', ['course_id' => $src->id, 'entitlement_ids' => $entitlements->pluck('id')->all()])
            ->assertSessionHasNoErrors();

        $jobs = $this->pushed(CouponRedeemed::class);
        $this->assertCount(2, $jobs);
        $this->assertTrue($jobs->every(fn ($job) => $job->notification->schoolId === $tenant->id));

        // Wie im Worker: ohne Request-Kontext wiederhergestellt, Link unter der Adresse der Schule.
        app(TenantContext::class)->clear();
        $job = unserialize(serialize($jobs->first()));
        $mail = $job->notification->toMail($job->notifiables->first());
        $this->assertSame('Dein Kurszugang wurde verlängert', $mail->subject);
        $this->assertStringContainsString('/'.$tenant->slug.'/courses/', $mail->actionUrl);
    }

    public function test_reminding_many_learners_queues_and_claims_the_reminders(): void
    {
        Queue::fake();
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $src = $this->existingCourse('SRC');
        $learners = collect(range(1, 11))->map(fn () => $this->createTenantUser($tenant, 'learner'));
        $learners->each(fn ($l) => $this->grantEntitlement($tenant, $l, $src));

        $this->actingAsInTenant($admin, $tenant)->post('/admin/inactive/remind', ['days' => 14])
            ->assertSessionHas('status', '11 Erinnerungen werden im Hintergrund verschickt.');

        $this->assertCount(11, $this->pushed(LearningReminder::class));
        $this->assertSame(11, $this->onAdmin(fn () => TenantUser::where('tenant_id', $tenant->id)->where('role', 'learner')->whereNotNull('reminded_at')->count()));
    }
}
