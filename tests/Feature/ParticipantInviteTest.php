<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

    /**
     * Bug-Review Testisolation: die Einladung löst per BestEffortMail einen
     * Passwort-Set-Link aus, der eine Zeile in password_reset_tokens
     * anlegt -- diese Tabelle hat keinen Fremdschlüssel (nur per
     * E-Mail-String geschlüsselt, Laravel-Standardschema) und wurde beim
     * bloßen $user->delete() hier nie mitgelöscht.
     */
    private function deleteInvitedUser(User $user): void
    {
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        $user->delete();
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
            $this->deleteInvitedUser($user);
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
            $this->deleteInvitedUser($user);
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
            $this->deleteInvitedUser($user);
        });
    }

    /**
     * Bug-Review H3 (Rest): /coupons/redeem schließt Instructor bewusst aus
     * (siehe Kommentar dort, routes/web.php) -- diese Route lässt auch
     * Instructor zu und erlaubte bisher trotzdem, über den optionalen
     * code-Parameter beim Einladen eines NEUEN Teilnehmers einen Coupon
     * sofort einzulösen, ohne Owner/Admin-Freigabe.
     */
    public function test_an_instructor_cannot_assign_a_code_while_inviting_a_new_participant(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $course = $this->existingCourse('SRC');
        $code = 'E2E-INVITE-INSTRUCTOR';
        $this->createdCodes[] = $code;
        $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));

        $response = $this->actingAsInTenant($instructor, $tenant)->post('/admin/participants/invite', [
            'name' => 'Von Instructor Eingeladen',
            'email' => 'instructor-invite-'.uniqid().'@example.test',
            'code' => $code,
        ]);

        $response->assertSessionHasErrors('code');

        $this->onAdmin(function () use ($code) {
            $user = User::where('display_name', 'Von Instructor Eingeladen')->first();
            $this->assertNotNull($user, 'Die Einladung soll trotz abgelehntem Code durchgehen.');
            $this->assertCount(0, $user->entitlements);

            $coupon = Coupon::where('code', $code)->first();
            $this->assertFalse($coupon->isRedeemed());

            $this->deleteInvitedUser($user);
        });
    }

    /**
     * Bug-Review R4-E (Folgefehler M3/H3-Rest): die Instructor-Sperre für
     * den code-Parameter griff bisher nur im Neukonto-Zweig -- lud ein
     * Instructor stattdessen eine Adresse ein, zu der schon IRGENDWO ein
     * Konto existiert, bekam er trotz beigelegtem Code nur den stillen
     * Status "Einladung verschickt." samt Zusatzsatz statt einer
     * Fehlermeldung. Dieser Unterschied im Antwortformat (Fehler vs. stiller
     * Zusatzsatz) verriet einem Instructor weiter, ob zu einer beliebigen
     * Adresse schon ein Konto existiert -- exakt dasselbe Muster wie beim
     * Neukonto-Zweig oben, nur im jeweils anderen Zweig.
     */
    public function test_an_instructor_cannot_assign_a_code_while_inviting_an_existing_account_either(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $course = $this->existingCourse('SRC');
        $code = 'E2E-INVITE-INSTRUCTOR-EXIST';
        $this->createdCodes[] = $code;
        $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));

        $existingEmail = 'instructor-invite-existing-'.uniqid().'@example.test';
        $this->onAdmin(fn () => User::create([
            'email' => $existingEmail,
            'display_name' => 'Bereits Registriert',
            'password' => bcrypt('irrelevant-password'),
            'status' => 'active',
        ]));

        $response = $this->actingAsInTenant($instructor, $tenant)->post('/admin/participants/invite', [
            'name' => 'Bereits Registriert',
            'email' => $existingEmail,
            'code' => $code,
        ]);

        $response->assertSessionHasErrors('code');

        $this->onAdmin(function () use ($code, $existingEmail) {
            $coupon = Coupon::where('code', $code)->first();
            $this->assertFalse($coupon->isRedeemed());

            $this->deleteInvitedUser(User::where('email', $existingEmail)->first());
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
            $this->deleteInvitedUser($user);
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
            $this->deleteInvitedUser($user);
        });
    }

    /**
     * Bug-Review R3-8: ohne Throttle ließ sich dieser Endpoint beliebig oft
     * mit wechselnden E-Mail-Adressen aufrufen -- trotz identischer Antwort
     * für "Konto existiert bereits" vs. "neu" (Bug-Review M3, Folgefehler)
     * bleibt bei unbegrenzter Anfragerate ein statistischer
     * Timing-Seitenkanal realistisch nutzbar, und jede beliebige echte
     * Adresse ließ sich unbegrenzt mit Einladungs-/Reset-Mails zuspammen.
     */
    public function test_inviting_participants_is_rate_limited(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        for ($i = 0; $i < 10; $i++) {
            $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/invite', [
                'name' => 'Rate Limit Test',
                'email' => 'rate-limit-'.$i.'-'.uniqid().'@example.test',
            ]);
            $this->assertNotEquals(429, $response->status());
        }

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/invite', [
            'name' => 'Rate Limit Test',
            'email' => 'rate-limit-one-more-'.uniqid().'@example.test',
        ]);

        $response->assertStatus(429);

        $this->onAdmin(function () {
            User::where('display_name', 'Rate Limit Test')->get()->each(fn (User $user) => $this->deleteInvitedUser($user));
        });
    }
}
