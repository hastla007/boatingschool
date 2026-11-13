<?php

namespace Tests\Feature;

use App\Models\Entitlement;
use App\Models\Tenant;
use App\Models\TenantAnnouncement;
use App\Models\User;
use App\Notifications\AnnouncementPublished;
use App\Notifications\EntitlementExpiringSoon;
use App\Notifications\LearningReminder;
use App\Support\MailPreferences;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mime\Email;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * E-Mail-Benachrichtigungen (§ 7 UWG): Schalter im Profil, Benachrichtigungen
 * respektieren sie, jede dieser Mails trägt einen signierten Abmeldelink
 * (sichtbar und als List-Unsubscribe-Header), der ohne Anmeldung wirkt.
 * Sicherheits- und Kontomails bleiben unberührt.
 */
class MailPreferencesTest extends TestCase
{
    use InteractsWithTenants;

    private function fresh(User $user): User
    {
        return $this->onAdmin(fn () => User::find($user->id));
    }

    public function test_the_profile_switches_store_the_preferences_and_the_notifications_respect_them(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $this->actingAsInTenant($learner, $tenant)->get('/profile')->assertOk()
            ->assertSee('E-Mail-Benachrichtigungen')->assertSee('Lern-Erinnerungen')
            ->assertSee('Hinweise vor Ablauf des Kurszugangs und Verlängerungsangebote')->assertSee('Mitteilungen der Bootsschule');

        $this->actingAsInTenant($learner, $tenant)->patch('/profile/mail-preferences', [
            'learning_reminders' => '0', 'expiry' => '1', 'announcements' => '0',
        ])->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

        $user = $this->fresh($learner);
        $this->assertSame(['learning_reminders' => false, 'expiry' => true, 'announcements' => false], MailPreferences::forUser($user));

        $announcement = $this->asTenant($tenant, fn () => TenantAnnouncement::create(['tenant_id' => $tenant->id, 'title' => 'Info', 'body' => 'Text', 'created_by_user_id' => $learner->id]));
        $entitlement = $this->grantEntitlement($tenant, $user, $this->existingCourse('SRC'));
        $user->notify(new LearningReminder($tenant, 5));
        $user->notify(new AnnouncementPublished($tenant, $announcement));
        $user->notify(new EntitlementExpiringSoon($entitlement));
        $user->notify(new ResetPassword('token'));

        Notification::assertNotSentTo($user, LearningReminder::class);
        Notification::assertNotSentTo($user, AnnouncementPublished::class);
        Notification::assertSentTo($user, EntitlementExpiringSoon::class);
        // Konto- und Sicherheitsmails sind nicht abbestellbar.
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertSame(['mail'], (new ResetPassword('token'))->via($user));

        // Wieder einschalten.
        $this->actingAsInTenant($learner, $tenant)->patch('/profile/mail-preferences', [
            'learning_reminders' => '1', 'expiry' => '1', 'announcements' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame(['mail'], (new LearningReminder($tenant, 5))->via($this->fresh($learner)));

        $this->onAdmin(fn () => Entitlement::whereKey($entitlement->id)->delete());
    }

    public function test_the_mails_carry_a_visible_unsubscribe_link_and_one_click_headers(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $mail = (new LearningReminder($tenant, 5))->toMail($learner);
        $html = (string) $mail->render();
        $url = MailPreferences::unsubscribeUrl($learner, MailPreferences::LEARNING_REMINDERS, $tenant);

        $this->assertStringContainsString('Abmelden', $html);
        $this->assertStringContainsString(e($url), $html);
        $this->assertStringContainsString('/'.$tenant->slug.'/mail/abmelden/'.$learner->id.'/learning_reminders', $url);

        $email = new Email;
        foreach ($mail->callbacks as $callback) {
            $callback($email);
        }
        $this->assertSame('<'.$url.'>', $email->getHeaders()->get('List-Unsubscribe')->getBodyAsString());
        $this->assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->get('List-Unsubscribe-Post')->getBodyAsString());
    }

    public function test_the_signed_link_unsubscribes_without_login_and_only_with_a_valid_signature(): void
    {
        $tenant = $this->createTestTenant();
        // Pfad-Modus: echte Slugs sind kleingeschrieben.
        $tenant->slug = strtolower($tenant->slug);
        $this->onAdmin(fn () => Tenant::where('id', $tenant->id)->update(['slug' => $tenant->slug]));
        $learner = $this->createTenantUser($tenant, 'learner');
        $url = MailPreferences::unsubscribeUrl($learner, MailPreferences::ANNOUNCEMENTS, $tenant);

        // GET (z. B. Link-Scanner) meldet nicht ab, zeigt nur die Bestätigung.
        $this->get($url)->assertOk()->assertSee('Mitteilungen der Bootsschule')->assertSee('Abmelden');
        $this->assertTrue(MailPreferences::wants($this->fresh($learner), MailPreferences::ANNOUNCEMENTS));

        // Manipulierte Signatur bzw. andere Kategorie mit fremder Signatur: abgelehnt.
        $this->post(str_replace('signature=', 'signature=0', $url))->assertForbidden();
        $this->post(str_replace('/announcements', '/expiry', $url))->assertForbidden();
        $this->assertTrue(MailPreferences::wants($this->fresh($learner), MailPreferences::ANNOUNCEMENTS));

        // Ein-Klick-Abmeldung (RFC 8058): POST ohne Sitzung und CSRF-Token.
        $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee('Du bist abgemeldet');
        $user = $this->fresh($learner);
        $this->assertFalse(MailPreferences::wants($user, MailPreferences::ANNOUNCEMENTS));
        $this->assertTrue(MailPreferences::wants($user, MailPreferences::LEARNING_REMINDERS));

        $this->get($url)->assertOk()->assertSee('bereits abgemeldet');
    }

    public function test_opted_out_learners_are_skipped_and_not_counted_when_an_announcement_is_mailed(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $wants = $this->createTenantUser($tenant, 'learner');
        $optedOut = $this->createTenantUser($tenant, 'learner', ['mail_preferences' => ['announcements' => false]]);
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $wants, $course);
        $this->grantEntitlement($tenant, $optedOut, $course);

        $this->actingAsInTenant($admin, $tenant)->post('/admin/announcements', ['title' => 'Info', 'body' => 'Text', 'send_mail' => '1'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', fn ($status) => str_contains($status, 'an 1 Person gemailt'));

        Notification::assertSentTo($wants, AnnouncementPublished::class);
        Notification::assertNotSentTo($optedOut, AnnouncementPublished::class);
    }
}
