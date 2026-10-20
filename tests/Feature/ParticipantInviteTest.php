<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\User;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Teilnehmer einladen (siehe ParticipantController): die Bootsschule kann
 * optional gleich die Adresse hinterlegen (z. B. bei Vor-Ort-Anmeldung) --
 * das Geburtsdatum bewusst nicht, das muss der Lerner immer selbst im
 * eigenen Profil angeben (siehe PraxisPruefungController-Gate). Ebenfalls
 * optional: ein eigener Code, der dem neuen Teilnehmer direkt im selben
 * Schritt zugewiesen/eingelöst wird (Gegenstück zu
 * Admin\CouponController::assign, nur zusammen mit der Einladung statt
 * danach separat).
 */
class ParticipantInviteTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCodes = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach ($this->createdCodes as $code) {
                Coupon::where('code', $code)->delete();
            }
        });

        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

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

    public function test_admin_can_invite_a_participant_and_assign_a_code_in_one_step(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');
        $code = 'E2E-INVITE-ASSIGN';
        $this->createdCodes[] = $code;
        $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/invite', [
            'name' => 'Teilnehmer Mit Code',
            'email' => 'mit-code-'.uniqid().'@example.test',
            'code' => $code,
        ]);

        $response->assertSessionHasNoErrors();
        $this->onAdmin(function () use ($tenant, $course, $code) {
            $user = User::where('display_name', 'Teilnehmer Mit Code')->first();
            $this->assertNotNull($user);
            $this->assertDatabaseHas('entitlement', [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'course_id' => $course->id,
                'source_type' => 'coupon',
                'source_reference' => $code,
            ]);
            $coupon = Coupon::where('code', $code)->first();
            $this->assertTrue($coupon->isRedeemed());
            $this->assertSame($user->id, $coupon->redeemed_by_user_id);
            $user->delete();
        });
    }

    public function test_an_unknown_code_does_not_block_the_invite_but_shows_an_error(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/invite', [
            'name' => 'Teilnehmer Falscher Code',
            'email' => 'falscher-code-'.uniqid().'@example.test',
            'code' => 'UNBEKANNT-0000',
        ]);

        $response->assertSessionHasErrors('code');
        $this->onAdmin(function () {
            $user = User::where('display_name', 'Teilnehmer Falscher Code')->first();
            $this->assertNotNull($user, 'Die Einladung soll trotz ungültigem Code durchgehen.');
            $this->assertCount(0, $user->entitlements);
            $user->delete();
        });
    }

    public function test_a_code_belonging_to_another_tenant_cannot_be_assigned_when_inviting(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $adminA = $this->createTenantUser($tenantA, 'owner');
        $course = $this->existingCourse('SRC');
        $code = 'E2E-INVITE-CROSS-TENANT';
        $this->createdCodes[] = $code;
        $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenantB->id]));

        $response = $this->actingAsInTenant($adminA, $tenantA)->post('/admin/participants/invite', [
            'name' => 'Teilnehmer Fremder Code',
            'email' => 'fremder-code-'.uniqid().'@example.test',
            'code' => $code,
        ]);

        $response->assertSessionHasErrors('code');
        $this->onAdmin(function () use ($code) {
            $user = User::where('display_name', 'Teilnehmer Fremder Code')->first();
            $this->assertNotNull($user);
            $this->assertCount(0, $user->entitlements);
            $coupon = Coupon::where('code', $code)->first();
            $this->assertFalse($coupon->isRedeemed());
            $user->delete();
        });
    }
}
