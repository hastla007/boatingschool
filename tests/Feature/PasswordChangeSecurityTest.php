<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview S-N2 / M1/N6: ein Passwortwechsel muss auch
 * remember_token rotieren und alle ANDEREN laufenden Sitzungen beenden --
 * sonst meldet das "Angemeldet bleiben"-Cookie eines Angreifers (oder
 * einer alten, kompromittierten Sitzung) ihn beim nächsten Request
 * automatisch wieder an, ganz ohne das neue Passwort zu kennen.
 */
class PasswordChangeSecurityTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_changing_the_password_rotates_the_remember_token_and_ends_other_sessions(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner', ['remember_token' => 'original-remember-token']);

        $this->onAdmin(fn () => DB::table('sessions')->insert([
            'id' => 'other-device-session',
            'user_id' => $user->id,
            'payload' => base64_encode('test'),
            'last_activity' => now()->timestamp,
        ]));

        $response = $this->actingAsInTenant($user, $tenant)->put('/password', [
            'current_password' => 'password',
            'password' => 'ein-ganz-neues-passwort-456',
            'password_confirmation' => 'ein-ganz-neues-passwort-456',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $this->onAdmin(function () use ($user) {
            $this->assertNotSame('original-remember-token', $user->fresh()->remember_token);
            $this->assertDatabaseMissing('sessions', ['id' => 'other-device-session']);
        });

        // Die eigene, gerade genutzte Sitzung bleibt bewusst aktiv --
        // ohne erneuten Login funktioniert ein weiterer authentifizierter
        // Request noch.
        $this->assertAuthenticatedAs($user->fresh());
    }
}
