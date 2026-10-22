<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\TenantInvitation;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview H1 (Coupon-Doppeleinlösung) und H2 (Account
 * Pre-Hijacking über Einladungen an bereits existierende, nicht
 * verifizierte E-Mail-Adressen).
 */
class AccountSecurityTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    // -- H1: Coupon kann nicht zweimal eingelöst werden --------------------

    public function test_a_coupon_cannot_be_redeemed_twice_even_via_two_different_accounts(): void
    {
        $tenant = $this->createTestTenant();
        $learnerA = $this->createTenantUser($tenant, 'learner');
        $learnerB = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $code = 'E2E-DOUBLE-REDEEM';
        $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));

        $this->actingAsInTenant($learnerA, $tenant)->post('/coupons/redeem', ['code' => $code])
            ->assertSessionHasNoErrors();

        $second = $this->actingAsInTenant($learnerB, $tenant)->post('/coupons/redeem', ['code' => $code]);

        $second->assertSessionHasErrors('code');
        $this->assertSame(1, Entitlement::where('tenant_id', $tenant->id)->where('course_id', $course->id)
            ->where('source_type', 'coupon')->where('source_reference', $code)->count());

        $this->onAdmin(fn () => Coupon::where('code', $code)->delete());
    }

    // -- B10: Coupon-Einlösung für einen bereits freigeschalteten Kurs ------

    public function test_a_course_coupon_is_rejected_and_kept_unredeemed_if_the_user_already_has_access(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $code = 'E2E-ALREADY-OWNED';
        $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));

        $response = $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $code]);

        $response->assertSessionHasErrors('code');
        $this->assertSame(1, Entitlement::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
            ->where('course_id', $course->id)->count());
        $this->assertNull($this->onAdmin(fn () => Coupon::where('code', $code)->first())->redeemed_at);

        $this->onAdmin(fn () => Coupon::where('code', $code)->delete());
    }

    // -- H2: Einladung an eine bereits existierende Adresse -----------------

    public function test_inviting_an_existing_verified_account_does_not_attach_membership_immediately(): void
    {
        $victimTenant = $this->createTestTenant('Opfer Schule');
        $attackerVictim = $this->createTenantUser($victimTenant, 'learner', [
            'email' => 'opfer@example.test',
            'email_verified_at' => now(),
        ]);

        $invitingTenant = $this->createTestTenant('Einladende Schule');
        $admin = $this->createTenantUser($invitingTenant, 'owner');

        Notification::fake();

        $response = $this->actingAsInTenant($admin, $invitingTenant)->post('/admin/participants/invite', [
            'name' => 'Opfer',
            'email' => 'opfer@example.test',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseMissing('tenant_user', [
            'tenant_id' => $invitingTenant->id,
            'user_id' => $attackerVictim->id,
        ]);

        $this->assertDatabaseHas('tenant_invitation', [
            'tenant_id' => $invitingTenant->id,
            'email' => 'opfer@example.test',
        ]);

        Notification::assertSentOnDemand(TenantInvitationNotification::class);
    }

    public function test_the_real_owner_can_accept_the_invitation_by_logging_in_with_the_invited_address(): void
    {
        $victimTenant = $this->createTestTenant('Opfer Schule');
        $victim = $this->createTenantUser($victimTenant, 'learner', [
            'email' => 'opfer2@example.test',
            'email_verified_at' => now(),
        ]);

        $invitingTenant = $this->createTestTenant('Einladende Schule');
        $admin = $this->createTenantUser($invitingTenant, 'owner');

        $this->actingAsInTenant($admin, $invitingTenant)->post('/admin/participants/invite', [
            'name' => 'Opfer',
            'email' => 'opfer2@example.test',
        ]);

        $invitation = TenantInvitation::where('tenant_id', $invitingTenant->id)->where('email', 'opfer2@example.test')->firstOrFail();

        $url = URL::temporarySignedRoute('invitations.accept', now()->addDays(7), [
            'invitation' => $invitation->id,
            'hash' => sha1('opfer2@example.test'),
        ]);

        $response = $this->actingAsInTenant($victim, $invitingTenant)->get($url);

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $invitingTenant->id,
            'user_id' => $victim->id,
        ]);
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    /**
     * Bug-Review H2: eine frühere Fassung hat das unverifizierte Konto des
     * Angreifers hier zusätzlich präventiv ausgeloggt und sein Passwort
     * randomisiert. Das war redundant (die hasVerifiedEmail()-Prüfung beim
     * Annehmen -- siehe unten -- verhindert die Übernahme schon allein) und
     * machte jede Einladung an eine noch unbestätigte, aber völlig legitime
     * Adresse zu einem ungewollten Logout+Passwort-Reset für deren echten
     * Besitzer. Entfernt; dieser Test prüft seitdem nur noch die eigentliche
     * Schutzwirkung: die Einladung bleibt für den Angreifer unannehmbar.
     */
    public function test_an_attacker_who_pre_registered_the_victims_email_cannot_accept_the_invitation(): void
    {
        $invitingTenant = $this->createTestTenant('Einladende Schule');
        $admin = $this->createTenantUser($invitingTenant, 'owner');

        // Angreifer registriert die Adresse des Opfers zuerst, unverifiziert.
        $attacker = $this->createTenantUser($this->createTestTenant('Angreifer Schule'), 'learner', [
            'email' => 'vorab-registriert@example.test',
            'email_verified_at' => null,
        ]);

        $this->actingAsInTenant($admin, $invitingTenant)->post('/admin/participants/invite', [
            'name' => 'Opfer',
            'email' => 'vorab-registriert@example.test',
        ]);

        $invitation = TenantInvitation::where('tenant_id', $invitingTenant->id)->where('email', 'vorab-registriert@example.test')->firstOrFail();
        $url = URL::temporarySignedRoute('invitations.accept', now()->addDays(7), [
            'invitation' => $invitation->id,
            'hash' => sha1('vorab-registriert@example.test'),
        ]);

        // Der Angreifer versucht mit seinem eigenen, unverifizierten Konto anzunehmen.
        $response = $this->actingAsInTenant($attacker, $invitingTenant)->get($url);

        $response->assertForbidden();
        $this->assertDatabaseMissing('tenant_user', [
            'tenant_id' => $invitingTenant->id,
            'user_id' => $attacker->id,
        ]);
    }

    /**
     * Bug-Review H2: die inzwischen entfernte präventive Passwort-Entwertung
     * traf JEDES unverifizierte Konto, dessen Adresse irgendwo eingeladen
     * wurde -- unabhängig davon, ob überhaupt ein Zusammenhang zu dieser
     * Bootsschule bestand. Das machte "jemanden einladen" zu einer Waffe
     * gegen x-beliebige, fremde Adressen (erzwungener Passwort-Reset für
     * den echten, völlig unbeteiligten Kontoinhaber).
     */
    public function test_inviting_an_unrelated_persons_unverified_email_does_not_reset_their_password(): void
    {
        $bystanderTenant = $this->createTestTenant('Unbeteiligte Schule');
        $bystander = $this->createTenantUser($bystanderTenant, 'learner', [
            'email' => 'unbeteiligt@example.test',
            'email_verified_at' => null,
        ]);
        $bystanderOriginalPassword = $bystander->password;

        $invitingTenant = $this->createTestTenant('Fremde Schule');
        $admin = $this->createTenantUser($invitingTenant, 'owner');

        $this->actingAsInTenant($admin, $invitingTenant)->post('/admin/participants/invite', [
            'name' => 'Unbeteiligt',
            'email' => 'unbeteiligt@example.test',
        ]);

        $this->assertSame($bystanderOriginalPassword, $bystander->fresh()->password);
    }

    public function test_inviting_a_brand_new_email_still_creates_the_membership_directly(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $email = 'brandneu-'.uniqid().'@example.test';

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/invite', [
            'name' => 'Neu',
            'email' => $email,
        ]);

        $response->assertRedirect();
        $user = User::where('email', $email)->first();
        $this->assertNotNull($user);
        $this->assertDatabaseHas('tenant_user', ['tenant_id' => $tenant->id, 'user_id' => $user->id]);
        $this->createdUserIds[] = $user->id;
    }
}
