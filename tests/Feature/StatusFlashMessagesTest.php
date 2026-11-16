<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Grüne Statusmeldung im App-Layout: interne Statusschlüssel werden als
 * deutscher Text gezeigt, nie roh.
 */
class StatusFlashMessagesTest extends TestCase
{
    use InteractsWithTenants;

    private array $userIds = [];

    protected function tearDown(): void
    {
        $this->onAdmin(fn () => AuditLog::whereIn('entity_id', $this->userIds)->delete());
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    public function test_mail_preferences_and_speech_consent_show_german_texts(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->userIds[] = $learner->id;

        $this->actingAsInTenant($learner, $tenant)->followingRedirects()
            ->patch('/profile/mail-preferences', ['learning_reminders' => 0])->assertOk()
            ->assertSee('E-Mail-Benachrichtigungen gespeichert.')->assertDontSee('mail-preferences-updated');

        $this->actingAsInTenant($learner, $tenant)->followingRedirects()->from('/profile')
            ->post('/profile/speech-consent')->assertOk()
            ->assertSee('Einwilligung zur Spracherkennung gespeichert.')->assertDontSee('speech-consent-');

        $this->actingAsInTenant($learner, $tenant)->followingRedirects()->from('/profile')
            ->delete('/profile/speech-consent')->assertOk()
            ->assertSee('Einwilligung zur Spracherkennung widerrufen.')->assertDontSee('speech-consent-');
    }

    public function test_unknown_status_keys_get_a_generic_text_and_sentences_stay(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $this->actingAsInTenant($learner, $tenant)->withSession(['dev_tenant_slug' => $tenant->slug, 'status' => 'something-new-updated'])
            ->get('/profile')->assertOk()->assertSee('Änderungen gespeichert.')->assertDontSee('something-new-updated');

        $this->actingAsInTenant($learner, $tenant)->withSession(['dev_tenant_slug' => $tenant->slug, 'status' => 'Addons aktualisiert.'])
            ->get('/profile')->assertOk()->assertSee('Addons aktualisiert.');
    }
}
