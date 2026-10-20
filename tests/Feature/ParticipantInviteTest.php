<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Teilnehmer einladen (siehe ParticipantController): die Bootsschule kann
 * optional gleich die Adresse hinterlegen (z. B. bei Vor-Ort-Anmeldung) --
 * das Geburtsdatum bewusst nicht, das muss der Lerner immer selbst im
 * eigenen Profil angeben (siehe PraxisPruefungController-Gate).
 */
class ParticipantInviteTest extends TestCase
{
    use InteractsWithTenants;

    public function test_admin_can_invite_a_participant_with_an_address(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/invite', [
            'name' => 'Neuer Teilnehmer',
            'email' => 'neuer-teilnehmer-'.uniqid().'@example.test',
            'street' => 'Marktplatz 14',
            'postal_code' => '4720',
            'city' => 'Neumarkt',
            'country' => 'Österreich',
        ]);

        $response->assertRedirect();
        $this->onAdmin(function () {
            $user = User::where('display_name', 'Neuer Teilnehmer')->first();
            $this->assertNotNull($user);
            $this->assertSame('Marktplatz 14', $user->street);
            $this->assertSame('4720', $user->postal_code);
            $this->assertSame('Neumarkt', $user->city);
            $this->assertSame('Österreich', $user->country);
            $this->assertNull($user->birthdate);
            $user->delete();
        });
    }

    public function test_admin_can_invite_a_participant_without_an_address(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/invite', [
            'name' => 'Teilnehmer Ohne Adresse',
            'email' => 'ohne-adresse-'.uniqid().'@example.test',
        ]);

        $response->assertRedirect();
        $this->onAdmin(function () {
            $user = User::where('display_name', 'Teilnehmer Ohne Adresse')->first();
            $this->assertNotNull($user);
            $this->assertNull($user->street);
            $user->delete();
        });
    }

    public function test_invited_participants_country_must_be_supported(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/invite', [
            'name' => 'Ungültiges Land',
            'email' => 'ungueltig-'.uniqid().'@example.test',
            'country' => 'Elbonien',
        ]);

        $response->assertSessionHasErrors('country');
    }
}
