<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PlatformAnnouncement;
use App\Models\TenantUser;
use App\Notifications\PlatformAnnouncementPublished;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Superadmin → Mitteilung an alle (oder ausgewählte) Bootsschulen. */
class PlatformAnnouncementTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->onAdmin(fn () => PlatformAnnouncement::query()->delete());
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    public function test_announcement_to_selected_schools_reaches_their_admin_area_and_mails_owners_and_admins_once(): void
    {
        Notification::fake();
        $superadmin = $this->createSuperAdmin();
        $a = $this->createTestTenant('Schule A');
        $b = $this->createTestTenant('Schule B');
        $c = $this->createTestTenant('Schule C');
        $owner = $this->createTenantUser($a, 'owner');
        $admin = $this->createTenantUser($a, 'admin');
        $instructor = $this->createTenantUser($a, 'instructor');
        $learner = $this->createTenantUser($a, 'learner');
        $this->grantEntitlement($a, $learner, $this->existingCourse('SRC'));
        $adminB = $this->createTenantUser($b, 'admin');
        // Admin in A und C: bekommt trotzdem nur eine Mail.
        $this->onAdmin(fn () => TenantUser::create(['tenant_id' => $c->id, 'user_id' => $admin->id, 'role' => 'admin', 'status' => 'active']));

        $this->actingAs($superadmin)->get('/superadmin/announcements')->assertOk()->assertSee('Neue Mitteilung')->assertSee($b->name);
        $this->actingAs($superadmin)->post('/superadmin/announcements', [
            'title' => 'Wartung am Sonntag', 'body' => 'Die Plattform ist von 6 bis 8 Uhr nicht erreichbar.',
            'target' => 'selected', 'tenant_ids' => [$a->id, $c->id], 'audience' => 'team', 'send_mail' => '1',
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Mitteilung veröffentlicht und wird an 2 Personen verschickt.');

        Notification::assertSentToTimes($owner, PlatformAnnouncementPublished::class, 1);
        Notification::assertSentToTimes($admin, PlatformAnnouncementPublished::class, 1);
        Notification::assertNotSentTo($instructor, PlatformAnnouncementPublished::class);
        Notification::assertNotSentTo($learner, PlatformAnnouncementPublished::class);
        Notification::assertNotSentTo($adminB, PlatformAnnouncementPublished::class);

        $this->actingAsInTenant($instructor, $a)->get('/admin')->assertOk()->assertSee('Wartung am Sonntag')->assertSee('von 6 bis 8 Uhr');
        $this->actingAsInTenant($adminB, $b)->get('/admin')->assertOk()->assertDontSee('Wartung am Sonntag');
        $this->actingAsInTenant($learner, $a)->get('/dashboard')->assertOk()->assertDontSee('Wartung am Sonntag');

        $announcement = $this->onAdmin(fn () => PlatformAnnouncement::firstOrFail());
        $this->assertSame(2, $announcement->emailed_count);
        $this->assertTrue($this->onAdmin(fn () => AuditLog::where('action', 'superadmin.platform_announcement.create')->where('entity_id', $announcement->id)->exists()));

        // Mailinhalt: Text der Mitteilung, Link in den Admin-Bereich der Schule.
        $mail = $this->onAdmin(fn () => (new PlatformAnnouncementPublished($announcement, $a))->toMail($owner));
        $this->assertSame('Wartung am Sonntag', $mail->subject);
        $this->assertStringContainsString('von 6 bis 8 Uhr', implode(' ', $mail->introLines));
        $this->assertStringEndsWith('/admin', $mail->actionUrl);
    }

    public function test_announcement_for_everyone_shows_to_learners_until_it_expires_or_is_deleted(): void
    {
        Notification::fake();
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $learner, $this->existingCourse('SRC'));

        $this->actingAs($superadmin)->post('/superadmin/announcements', [
            'title' => 'Neue Prüfungsfragen ab Januar', 'body' => 'Der Fragenkatalog wurde aktualisiert.', 'target' => 'all', 'audience' => 'all',
        ])->assertSessionHas('status', 'Mitteilung veröffentlicht.');
        Notification::assertNothingSent();

        $this->actingAsInTenant($learner, $tenant)->get('/dashboard')->assertSee('Neue Prüfungsfragen ab Januar');
        $this->actingAsInTenant($admin, $tenant)->get('/admin')->assertSee('Neue Prüfungsfragen ab Januar');

        $announcement = $this->onAdmin(fn () => PlatformAnnouncement::firstOrFail());
        $this->assertNull($announcement->tenant_ids);
        $this->onAdmin(fn () => $announcement->update(['visible_until' => now()->subDay()->toDateString()]));
        $this->actingAsInTenant($learner, $tenant)->get('/dashboard')->assertDontSee('Neue Prüfungsfragen ab Januar');

        $this->actingAs($superadmin)->delete("/superadmin/announcements/{$announcement->id}")->assertSessionHas('status', 'Mitteilung gelöscht.');
        $this->assertSame(0, $this->onAdmin(fn () => PlatformAnnouncement::count()));
    }

    public function test_only_superadmins_and_selected_target_needs_schools(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $this->actingAsInTenant($owner, $tenant)->get('/superadmin/announcements')->assertForbidden();
        $this->actingAsInTenant($owner, $tenant)->post('/superadmin/announcements', ['title' => 'x', 'body' => 'y', 'target' => 'all', 'audience' => 'team'])->assertForbidden();

        $this->actingAs($superadmin)->post('/superadmin/announcements', ['title' => 'x', 'body' => 'y', 'target' => 'selected', 'audience' => 'team'])
            ->assertSessionHasErrors(['tenant_ids' => 'Bitte mindestens eine Bootsschule auswählen.']);
        $this->actingAs($superadmin)->post('/superadmin/announcements', ['title' => 'x', 'body' => 'y', 'target' => 'all', 'audience' => 'jeder'])
            ->assertSessionHasErrors('audience');
        $this->assertSame(0, $this->onAdmin(fn () => PlatformAnnouncement::count()));
    }
}
