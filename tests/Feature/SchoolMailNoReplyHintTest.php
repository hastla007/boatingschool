<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\TenantBranding;
use App\Notifications\AccountInvitation;
use App\Notifications\Concerns\AddsNoReplyHint;
use App\Notifications\CouponRedeemed;
use App\Notifications\LearningReminder;
use App\Support\SchoolMails;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Mails einer Bootsschule ohne bestätigte Support-Adresse: Antworten
 * landeten bei der Absenderadresse der Plattform -- die Mail bekommt
 * deshalb eine Schlusszeile „Bitte antworte nicht …“ (Teilnehmer geduzt,
 * Team gesiezt). Mit bestätigter Adresse geht „Antworten“ an die Schule,
 * die Zeile entfällt.
 */
class SchoolMailNoReplyHintTest extends TestCase
{
    use InteractsWithTenants;

    private const LEARNER_HINT = 'Bitte antworte nicht auf diese E-Mail. Bei Fragen wende dich direkt an deine Bootsschule.';

    private const TEAM_HINT = 'Bitte antworten Sie nicht auf diese E-Mail. Bei Fragen wenden Sie sich direkt an die Bootsschule.';

    public function test_every_school_mail_uses_the_hint(): void
    {
        foreach (SchoolMails::NOTIFICATIONS as $class) {
            $this->assertContains(AddsNoReplyHint::class, class_uses($class), $class.' muss die Schlusszeile ohne Antwortadresse setzen.');
        }
    }

    public function test_learner_mail_without_verified_support_address_ends_with_the_informal_hint(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $product = Product::where('code', 'FAHRSTUNDE-1-EH')->first();

        $html = (string) $this->asTenant($tenant->fresh(), fn () => (new CouponRedeemed(['type' => 'product', 'product' => $product]))->toMail($learner)->render());

        $this->assertStringContainsString(self::LEARNER_HINT, $html);
        $this->assertStringNotContainsString('Bitte antworten Sie', $html);
    }

    public function test_an_unverified_support_address_still_gets_the_hint(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->onAdmin(fn () => TenantBranding::where('tenant_id', $tenant->id)->update(['support_email' => 'info@schule.test', 'support_email_verified_at' => null]));

        $mail = $this->asTenant($tenant->fresh(), fn () => (new LearningReminder($tenant, 10))->toMail($learner));

        $this->assertSame(self::LEARNER_HINT, end($mail->outroLines));
    }

    public function test_no_hint_when_the_school_has_a_verified_support_address(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->onAdmin(fn () => TenantBranding::where('tenant_id', $tenant->id)->update(['support_email' => 'info@schule.test', 'support_email_verified_at' => now()]));

        $html = (string) $this->asTenant($tenant->fresh(), fn () => (new LearningReminder($tenant, 10))->toMail($learner)->render());

        $this->assertStringNotContainsString('Bitte antworte nicht', $html);
    }

    public function test_team_invitation_uses_the_formal_hint(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');

        $this->asTenant($tenant->fresh(), fn () => $instructor->notify(new AccountInvitation($tenant, 'instructor', 'https://example.test/passwort')));

        Notification::assertSentTo($instructor, AccountInvitation::class, function (AccountInvitation $notification) use ($instructor, $tenant) {
            $html = (string) $this->asTenant($tenant->fresh(), fn () => $notification->toMail($instructor)->render());

            return str_contains($html, self::TEAM_HINT) && ! str_contains($html, 'Bitte antworte nicht');
        });
    }

    public function test_platform_accounts_get_a_hint_without_a_school_reference(): void
    {
        $user = $this->createSuperAdmin();

        $mail = (new AccountInvitation(null, 'superadmin', 'https://example.test/passwort'))->toMail($user);

        $this->assertSame('Bitte antworten Sie nicht auf diese E-Mail – sie wurde automatisch versendet.', end($mail->outroLines));
    }
}
