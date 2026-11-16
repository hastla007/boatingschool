<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\PersonalDataExportService;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Spracherkennung des Browsers nur nach Einwilligung: Dialog vor der ersten
 * Nutzung, Einwilligung serverseitig gespeichert (speech_consent_at) und
 * protokolliert, Widerruf im Profil.
 */
class SpeechConsentTest extends TestCase
{
    use InteractsWithTenants;

    private function consentAt(User $user): mixed
    {
        return $this->onAdmin(fn () => User::find($user->id)->speech_consent_at);
    }

    public function test_the_endpoint_stores_and_revokes_the_consent_with_an_audit_trail(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->assertNull($this->consentAt($learner));

        $this->actingAsInTenant($learner, $tenant)->postJson('/profile/speech-consent')->assertOk()->assertJson(['granted' => true]);
        $this->assertNotNull($this->consentAt($learner));

        $this->actingAsInTenant($learner, $tenant)->deleteJson('/profile/speech-consent')->assertOk()->assertJson(['granted' => false]);
        $this->assertNull($this->consentAt($learner));

        $actions = $this->onAdmin(fn () => AuditLog::where('tenant_id', $tenant->id)->where('entity_id', $learner->id)->orderBy('created_at')->pluck('action')->all());
        $this->assertEqualsCanonicalizing(['profile.speech_consent.granted', 'profile.speech_consent.revoked'], $actions);
    }

    public function test_guests_cannot_set_a_consent(): void
    {
        $tenant = $this->createTestTenant();

        $this->withSession(['dev_tenant_slug' => $tenant->slug])->postJson('/profile/speech-consent')->assertUnauthorized();
    }

    public function test_the_profile_shows_the_state_and_revokes_the_consent(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $this->actingAsInTenant($learner, $tenant)->get('/profile')->assertOk()
            ->assertSee('Spracherkennung ist aus.')->assertDontSee('Einwilligung widerrufen');

        $this->onAdmin(fn () => User::whereKey($learner->id)->update(['speech_consent_at' => now()]));
        $learner = $this->onAdmin(fn () => User::find($learner->id));
        $this->actingAsInTenant($learner, $tenant)->get('/profile')->assertOk()
            ->assertSee('Eingewilligt am')->assertSee('Einwilligung widerrufen');

        $this->actingAsInTenant($learner, $tenant)->from('/profile')->delete('/profile/speech-consent')
            ->assertRedirect('/profile')->assertSessionHas('status', 'speech-consent-updated');
        $this->assertNull($this->consentAt($learner));
    }

    public function test_funk_pages_ask_before_the_first_use_and_know_an_existing_consent(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $page = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/funk/muendlich")->assertOk()
            ->assertSee('id="speech-consent-dialog"', false)->assertSee('Chrome und Edge an Google', false)
            ->assertSee('window.speechConsent', false);
        $this->assertStringContainsString('granted: false', $page->getContent());

        $this->onAdmin(fn () => User::whereKey($learner->id)->update(['speech_consent_at' => now()]));
        $learner = $this->onAdmin(fn () => User::find($learner->id));
        $page = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/funk/muendlich")->assertOk();
        $this->assertStringContainsString('granted: true', $page->getContent());
    }

    public function test_consents_are_part_of_the_data_export(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner', ['speech_consent_at' => now(), 'mail_preferences' => ['expiry' => false]]);

        $export = $this->asTenant($tenant, fn () => app(PersonalDataExportService::class)->export(User::find($learner->id), $tenant));

        $this->assertNotNull($export['profile']['age_confirmed_at']);
        $this->assertNotNull($export['profile']['speech_consent_at']);
        $this->assertSame(['learning_reminders' => true, 'expiry' => false, 'announcements' => true], $export['profile']['mail_preferences']);
    }
}
