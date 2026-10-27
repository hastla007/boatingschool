<?php

namespace Tests\Feature;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Niedrig): dasselbe Muster wie B6 (siehe
 * GermanValidationMessagesTest), nur für Mails statt Formular-Fehler.
 * Laravels Mail-Markdown-Vorlage (vendor/laravel/framework/.../
 * Notifications/resources/views/email.blade.php) und die eigenen
 * ResetPassword-/VerifyEmail-Benachrichtigungen übersetzen "Hello!",
 * "Regards,", "All rights reserved." u. a. per @lang()/Lang::get() mit
 * dem wörtlichen englischen Text als Übersetzungsschlüssel -- ohne eine
 * lang/de.json (Laravel 11 bringt dafür standardmäßig keinen lang/-Ordner
 * mehr mit) blieb trotz APP_LOCALE=de genau dieser Rahmentext englisch,
 * während der eigene Inhalt (Betreff/Zeilen der jeweiligen Notification)
 * längst Deutsch war.
 */
class GermanNotificationTranslationsTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_common_mail_framing_strings_are_translated(): void
    {
        $this->assertSame('Hallo!', trans('Hello!'));
        $this->assertSame('Viele Grüße,', trans('Regards,'));
        $this->assertSame('Alle Rechte vorbehalten.', trans('All rights reserved.'));
    }

    public function test_the_password_reset_mail_is_fully_german(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        Notification::fake();

        $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $mail = $notification->toMail($user);

            $this->assertSame('Passwort zurücksetzen', $mail->subject);
            $this->assertStringContainsString('Du erhältst diese E-Mail', implode(' ', $mail->introLines));
            $this->assertStringNotContainsString('You are receiving this email', implode(' ', $mail->introLines));

            return true;
        });
    }

    public function test_the_verify_email_mail_is_fully_german(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner', ['email_verified_at' => null]);

        $notification = new VerifyEmail;
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $mail = $notification->toMail($user);

        $this->assertSame('E-Mail-Adresse bestätigen', $mail->subject);
        $this->assertStringContainsString('Bitte klicke auf den Button', implode(' ', $mail->introLines));
        $this->assertStringNotContainsString('Please click the button', implode(' ', $mail->introLines));

        // $url wird nur gebaut, um sicherzustellen, dass die Route selbst
        // noch existiert -- der eigentliche Mail-Inhalt oben kommt aus
        // VerifyEmail::toMail() unabhängig von einer konkreten URL.
        $this->assertNotEmpty($url);
    }
}
