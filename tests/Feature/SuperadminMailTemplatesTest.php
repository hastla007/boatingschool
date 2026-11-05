<?php

namespace Tests\Feature;

use App\Models\Entitlement;
use App\Models\PlatformSetting;
use App\Notifications\EntitlementExpiringSoon;
use App\Support\MailTemplates;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class SuperadminMailTemplatesTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->onAdmin(fn () => PlatformSetting::query()->update(['mail_templates' => null]));
        MailTemplates::flush();
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    public function test_custom_subject_intro_and_outro_wrap_the_fixed_mail_content(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant('Vorlagen [Schule]');
        $learner = $this->createTenantUser($tenant, 'learner');
        $entitlement = $this->grantEntitlement($tenant, $learner, $this->existingCourse('SRC'));
        $this->onAdmin(fn () => Entitlement::where('id', $entitlement->id)->update(['valid_until' => now()->addDays(10)]));

        $this->actingAs($superadmin)->get('/superadmin/mail-templates')->assertOk()->assertSee('Kurszugang läuft bald ab')->assertSee('{datum}');
        $this->actingAs($superadmin)->patch('/superadmin/mail-templates', ['templates' => [
            'entitlement_expiring' => ['subject' => 'Erinnerung: {kurs} endet am {datum}', 'intro' => 'Hallo von {schule}!', 'outro' => 'Dein Team'],
            'invitation' => ['subject' => '', 'intro' => '', 'outro' => ''],
        ]])->assertSessionHasNoErrors();
        $this->assertSame(['entitlement_expiring'], array_keys($this->onAdmin(fn () => PlatformSetting::current()->mail_templates)));

        $mail = $this->onAdmin(fn () => (new EntitlementExpiringSoon(Entitlement::with('course', 'tenant.branding')->find($entitlement->id)))->toMail($learner));
        $this->assertStringStartsWith('Erinnerung: SRC', $mail->subject);
        $this->assertSame('Hallo von Vorlagen \[Schule\]!', $mail->introLines[0], 'Platzhalter als Text, nicht als Markdown');
        $this->assertStringContainsString('läuft am', $mail->introLines[1], 'fester Inhalt bleibt');
        $this->assertSame('Dein Team', end($mail->outroLines));
        $this->assertNotNull($mail->actionUrl);

        // Leeren: wieder der Standard.
        $this->actingAs($superadmin)->patch('/superadmin/mail-templates', ['templates' => []])->assertSessionHasNoErrors();
        $mail = $this->onAdmin(fn () => (new EntitlementExpiringSoon(Entitlement::with('course', 'tenant.branding')->find($entitlement->id)))->toMail($learner));
        $this->assertStringStartsWith('Dein Kurszugang läuft am', $mail->subject);
    }
}
