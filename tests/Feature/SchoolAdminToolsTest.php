<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\AuditLog;
use App\Models\ContentQuestionRevision;
use App\Models\Tenant;
use App\Models\TenantAnnouncement;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\AnnouncementPublished;
use App\Notifications\LearningReminder;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Bootsschul-Admin: Suche/Filter, Lernstand, Erinnerungen, Sammel-Einladung, Export, Mitteilungen, Statistik, Protokoll. */
class SchoolAdminToolsTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        $this->onAdmin(fn () => User::where('email', 'like', 'bulk-%@example.test')->delete());
        parent::tearDown();
    }

    private function attempt(Tenant $tenant, User $user, string $when): void
    {
        $revision = $this->onAdmin(fn () => ContentQuestionRevision::where('editorial_status', 'published')->firstOrFail());
        $this->onAdmin(fn () => Attempt::forceCreate([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'question_id' => $revision->question_id, 'revision_id' => $revision->id,
            'correct' => true, 'context' => 'smarttrainer', 'created_at' => $when,
        ]));
    }

    public function test_participants_can_be_searched_and_filtered(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $anna = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Anna Segler']);
        $bert = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Bert Motor']);
        $src = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $anna, $src);
        $this->onAdmin(fn () => TenantUser::where('tenant_id', $tenant->id)->where('user_id', $bert->id)->update(['status' => 'suspended']));

        // Die Admin-Seite rendert alle Tabs (z. B. Coupon-Zuweisung mit allen Teilnehmern): geprüft wird die gefilterte Liste selbst.
        $names = fn (array $q) => $this->actingAsInTenant($admin, $tenant)->get('/admin?'.http_build_query(['tab' => 'participants'] + $q))
            ->assertOk()->viewData('participants')->getCollection()->map(fn ($m) => $m->user->display_name)->all();
        $this->assertSame(['Anna Segler'], $names(['p_search' => 'segler']));
        $this->assertSame(['Bert Motor'], $names(['p_search' => $bert->email]));
        $this->assertSame(['Anna Segler'], $names(['p_course' => $src->id]));
        $this->assertSame(['Bert Motor'], $names(['p_status' => 'suspended']));
        $this->assertCount(2, $names(['p_search' => ['x'], 'p_course' => 'kaputt']));
        $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=participants&p_search=segler')->assertSee('1 Teilnehmer gefunden');
    }

    public function test_participant_detail_shows_progress_last_activity_and_readiness(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $learner, $this->existingCourse('SRC'));
        $this->attempt($tenant, $learner, now()->subDays(3)->toDateTimeString());

        $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}")->assertOk()
            ->assertSee('Lernstand')->assertSee('SRC')->assertSee('prüfungsreif ab')->assertSee('Zuletzt gelernt: '.now()->subDays(3)->timezone('Europe/Berlin')->format('d.m.Y'), false)
            ->assertSee('Noch keine Prüfungssimulation abgeschlossen.');
    }

    public function test_inactive_learners_are_listed_and_reminded_at_most_once_a_week(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'instructor');
        $src = $this->existingCourse('SRC');
        $idle = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Ida Idle']);
        $busy = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Bea Busy']);
        $never = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Nils Nie']);
        $noAccess = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Otto Ohne']);
        foreach ([$idle, $busy, $never] as $u) {
            $this->grantEntitlement($tenant, $u, $src);
        }
        $this->attempt($tenant, $idle, now()->subDays(20)->toDateTimeString());
        $this->attempt($tenant, $busy, now()->subDay()->toDateTimeString());

        $this->actingAsInTenant($admin, $tenant)->get('/admin/inactive?days=14')->assertOk()
            ->assertSee('Ida Idle')->assertSee('Nils Nie')->assertDontSee('Bea Busy')->assertDontSee('Otto Ohne')->assertSee('Alle erinnern (2)');

        $this->actingAsInTenant($admin, $tenant)->post('/admin/inactive/remind', ['days' => 14, 'user_id' => $idle->id])->assertSessionHas('status', 'Eine Erinnerung verschickt.');
        Notification::assertSentTo($idle, LearningReminder::class);
        $this->actingAsInTenant($admin, $tenant)->post('/admin/inactive/remind', ['days' => 14])->assertSessionHas('status', 'Eine Erinnerung verschickt.');
        Notification::assertSentTo($never, LearningReminder::class);
        Notification::assertSentToTimes($idle, LearningReminder::class, 1);
        $this->actingAsInTenant($admin, $tenant)->get('/admin/inactive?days=14')->assertDontSee('Alle erinnern');
    }

    public function test_bulk_invite_creates_invitations_and_skips_members_and_invalid_lines(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $member = $this->createTenantUser($tenant, 'learner');
        $lines = implode("\n", ['Max Muster, bulk-max@example.test', 'bulk-erika@example.test', 'Lisa Lot <bulk-lisa@example.test>', 'kein-mail', $member->email, 'bulk-max@example.test']);

        $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/bulk-invite', ['emails' => $lines])
            ->assertSessionHas('status', fn ($s) => str_starts_with($s, '3 Personen eingeladen.') && str_contains($s, 'kein-mail') && str_contains($s, 'bereits Teilnehmer'));
        $this->onAdmin(function () use ($tenant) {
            $max = User::where('email', 'bulk-max@example.test')->first();
            $this->assertSame('Max Muster', $max->display_name);
            $this->assertSame('Lisa Lot', User::where('email', 'bulk-lisa@example.test')->first()->display_name);
            $this->assertTrue(TenantUser::where('tenant_id', $tenant->id)->where('user_id', $max->id)->exists());
        });

        $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/bulk-invite', ['emails' => str_repeat("x@example.test\n", 101)])->assertSessionHasErrors('emails');
    }

    public function test_csv_export_respects_filters_and_is_logged(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $anna = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Anna Export']);
        $this->createTenantUser($tenant, 'learner', ['display_name' => 'Bert Anders']);
        $this->grantEntitlement($tenant, $anna, $this->existingCourse('SRC'));

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin/participants/export?p_search=Export')->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString($anna->email, $csv);
        $this->assertStringContainsString('SRC', $csv);
        $this->assertStringNotContainsString('Bert Anders', $csv);
        $this->assertTrue($this->onAdmin(fn () => AuditLog::where('tenant_id', $tenant->id)->where('action', 'participants.export')->exists()));

        $this->actingAsInTenant($instructor, $tenant)->get('/admin/participants/export')->assertForbidden();
    }

    public function test_announcements_reach_the_dashboard_of_the_right_learners_and_by_mail(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $src = $this->existingCourse('SRC');
        $ubi = $this->existingCourse('UBI');
        $srcLearner = $this->createTenantUser($tenant, 'learner');
        $ubiLearner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $srcLearner, $src);
        $this->grantEntitlement($tenant, $ubiLearner, $ubi);

        $this->actingAsInTenant($admin, $tenant)->get('/admin/announcements')->assertOk()->assertSee('Neue Mitteilung');
        $this->actingAsInTenant($admin, $tenant)->post('/admin/announcements', [
            'title' => 'Funkprüfung am Samstag', 'body' => 'Treffpunkt 9 Uhr am Hafen.', 'course_id' => $src->id, 'send_mail' => '1',
        ])->assertSessionHas('status', 'Mitteilung veröffentlicht und an 1 Person gemailt.');

        Notification::assertSentTo($srcLearner, AnnouncementPublished::class);
        Notification::assertNotSentTo($ubiLearner, AnnouncementPublished::class);
        $this->actingAsInTenant($srcLearner, $tenant)->get('/dashboard')->assertSee('Funkprüfung am Samstag')->assertSee('Treffpunkt 9 Uhr am Hafen.');
        $this->actingAsInTenant($ubiLearner, $tenant)->get('/dashboard')->assertDontSee('Funkprüfung am Samstag');

        // Abgelaufen bzw. gelöscht: nicht mehr sichtbar.
        $announcement = $this->onAdmin(fn () => TenantAnnouncement::where('tenant_id', $tenant->id)->first());
        $this->onAdmin(fn () => $announcement->update(['visible_until' => now()->subDays(2)->toDateString()]));
        $this->actingAsInTenant($srcLearner, $tenant)->get('/dashboard')->assertDontSee('Funkprüfung am Samstag');
        $this->actingAsInTenant($admin, $tenant)->delete("/admin/announcements/{$announcement->id}")->assertSessionHas('status');
        $this->assertSame(0, $this->onAdmin(fn () => TenantAnnouncement::where('tenant_id', $tenant->id)->count()));
    }

    public function test_statistics_and_full_audit_log(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $learner, $this->existingCourse('SRC'));
        $this->attempt($tenant, $learner, now()->subDays(2)->toDateTimeString());
        $this->onAdmin(function () use ($tenant, $admin) {
            foreach (range(1, 55) as $i) {
                AuditLog::record($tenant->id, $admin->id, 'branding.update', 'tenant_branding', $tenant->id, null, null);
            }
        });

        $this->actingAsInTenant($admin, $tenant)->get('/admin/statistics')->assertOk()
            ->assertSee('Davon aktiv in den letzten 30 Tagen')->assertSee('SRC')->assertViewHas('activeLearners30', 1)->assertViewHas('withAccess', 1);

        $audit = $this->actingAsInTenant($admin, $tenant)->get('/admin/audit')->assertOk()->assertSee('hat das Branding aktualisiert.');
        $audit->assertViewHas('entries', fn ($p) => $p->total() >= 55 && $p->count() === 50);
        $this->actingAsInTenant($admin, $tenant)->get('/admin/audit?action=gibtsnicht')->assertSee('Keine Einträge gefunden');

        $instructor = $this->createTenantUser($tenant, 'instructor');
        $this->actingAsInTenant($instructor, $tenant)->get('/admin/audit')->assertForbidden();
        $this->actingAsInTenant($instructor, $tenant)->get('/admin/statistics')->assertOk();
    }
}
