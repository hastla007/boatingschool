<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review R4-H: Illuminate\Routing\Middleware\ThrottleRequests baut den
 * Zähler für die bloße Zahlenform ("throttle:10,1") ohne einen eigenen
 * Prefix (3. Argument) für einen eingeloggten Nutzer ALLEIN aus dessen
 * Nutzer-ID (resolveRequestSignature()) -- unabhängig davon, welche Route
 * das Limit gerade auslöst. Mehrere Routen mit derselben bloßen Zahlenform
 * teilten sich dadurch EINEN gemeinsamen Zähler pro Nutzer: 10 Einladungen
 * blockierten damit zusätzlich den Coupon-Import (routes/web.php), ein paar
 * Fehlversuche beim Login blockierten forgot-password (routes/auth.php),
 * und umgekehrt -- unabhängig davon, wie viele Anfragen die jeweils andere
 * Route selbst schon verarbeitet hat. Jede Stelle hat jetzt einen eigenen
 * Prefix, der die Zähler trennt.
 */
class ThrottleKeyIsolationTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            User::where('display_name', 'Throttle Isolation Test')->get()
                ->each(fn (User $user) => $user->delete());
        });

        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_exhausting_the_participant_invite_limit_does_not_block_coupon_import_for_the_same_admin(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        for ($i = 0; $i < 10; $i++) {
            $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/invite', [
                'name' => 'Throttle Isolation Test',
                'email' => 'throttle-isolation-'.$i.'-'.uniqid().'@example.test',
            ]);
            $this->assertNotEquals(429, $response->status());
        }

        // Die Teilnehmer-Einladung ist jetzt ausgereizt (10/10) -- ohne
        // eigenen Prefix teilten sich beide Routen denselben Zähler, der
        // Coupon-Import wäre deshalb hier ebenfalls schon blockiert.
        $importResponse = $this->actingAsInTenant($admin, $tenant)->post('/admin/coupons/import', [
            'codes' => 'IRRELEVANT-CODE',
        ]);

        $this->assertNotEquals(
            429,
            $importResponse->status(),
            'Der Coupon-Import darf nicht vom separaten Einladungs-Throttle blockiert werden.'
        );
    }
}
