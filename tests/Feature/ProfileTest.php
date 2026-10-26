<?php

namespace Tests\Feature;

use App\Models\Entitlement;
use App\Models\Product;
use App\Models\ProductPurchase;
use App\Notifications\ConfirmPendingEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use InteractsWithTenants;

    public function test_profile_page_is_displayed(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $response = $this->actingAsInTenant($user, $tenant)->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);
        $originalEmail = $user->email;
        $newEmail = 'updated-'.uniqid().'@example.test';

        Notification::fake();

        $response = $this->actingAsInTenant($user, $tenant)->patch('/profile', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $newEmail,
            'phone' => '+49 30 1234567',
            'street' => 'Hafenstraße 1',
            'postal_code' => '12345',
            'city' => 'Hamburg',
            'country' => 'Deutschland',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test', $user->first_name);
        $this->assertSame('User', $user->last_name);
        $this->assertSame('Test User', $user->name);
        $this->assertSame('+49 30 1234567', $user->phone);
        $this->assertSame('Hafenstraße 1', $user->street);
        $this->assertSame('12345', $user->postal_code);
        $this->assertSame('Hamburg', $user->city);
        $this->assertSame('Deutschland', $user->country);

        // Eine geänderte E-Mail-Adresse wird erst nach Bestätigung über den
        // zugeschickten Link live geschaltet (Sicherheitsreview H2) -- bis
        // dahin bleibt die bisherige Adresse aktiv und verifiziert.
        $this->assertSame($originalEmail, $user->email);
        $this->assertSame($newEmail, $user->pending_email);
        $this->assertNotNull($user->email_verified_at);

        // Bug-Review H2 (Folgefehler, kritisch): die Bestätigungsmail muss
        // an die NEUE Adresse gehen, nicht an $user (= die aktuelle, alte
        // Adresse) -- sonst bestätigt der Nutzer den Besitz der neuen
        // Adresse nie und könnte jede fremde Adresse selbst für sein
        // eigenes Konto "bestätigen" (assertSentTo($user, ...) hätte genau
        // diesen Fehler zementiert, siehe Commit c54f535).
        Notification::assertNotSentTo($user, ConfirmPendingEmail::class);
        Notification::assertSentOnDemand(
            ConfirmPendingEmail::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $newEmail
        );
    }

    public function test_a_changed_email_takes_effect_only_after_confirmation(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);
        $newEmail = 'updated-'.uniqid().'@example.test';

        $this->actingAsInTenant($user, $tenant)->patch('/profile', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $newEmail,
        ])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame($newEmail, $user->pending_email);

        $url = URL::temporarySignedRoute(
            'profile.email.confirm',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($newEmail)]
        );

        $response = $this->actingAsInTenant($user, $tenant)->get($url);

        $response->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertSame($newEmail, $user->email);
        $this->assertNull($user->pending_email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_an_invalid_confirmation_hash_does_not_change_the_email(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);
        $newEmail = 'updated-'.uniqid().'@example.test';

        $this->actingAsInTenant($user, $tenant)->patch('/profile', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $newEmail,
        ]);

        $url = URL::temporarySignedRoute(
            'profile.email.confirm',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('someone-else@example.test')]
        );

        $response = $this->actingAsInTenant($user, $tenant)->get($url);

        $response->assertForbidden();
        $this->assertSame($newEmail, $user->fresh()->pending_email);
        $this->assertNotSame($newEmail, $user->fresh()->email);
    }

    /**
     * Bug-Review R3-6 (Folgefehler R4-B): eine synchrone
     * Eindeutigkeitsprüfung bei der Eingabe bestätigte dem einloggten
     * Nutzer bisher direkt, ob eine beliebige Adresse irgendwo auf der
     * Plattform bereits einem ANDEREN Konto gehört ("wird bereits
     * verwendet" vs. kein Fehler) -- ein Enumerations-Orakel über das
     * eigene Profil, zuvor nur per Throttle gebremst statt geschlossen
     * (dasselbe Muster wie M3 bei Login/Registrierung/Einladung). Die
     * Antwort beim Speichern des Profils ist jetzt unabhängig davon
     * identisch, ob die eingegebene Adresse bereits irgendwo vergeben ist
     * -- ein tatsächlicher Konflikt wird weiterhin sicher abgefangen, aber
     * erst beim Bestätigen über den zugeschickten Link (siehe
     * ProfileController::confirmEmail(),
     * test_confirming_an_email_that_became_taken_in_the_meantime_...
     * unten), nicht mehr gegenüber dem ursprünglich anfragenden Nutzer.
     */
    public function test_an_email_already_used_at_another_tenant_is_accepted_identically_to_an_available_one(): void
    {
        $otherTenant = $this->createTestTenant();
        $existing = $this->createTenantUser($otherTenant, 'learner', ['email' => 'anderswo-'.uniqid().'@example.test']);

        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $takenResponse = $this->actingAsInTenant($user, $tenant)->patch('/profile', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $existing->email,
        ]);

        $takenResponse->assertSessionHasNoErrors();
        $this->assertSame($existing->email, $user->fresh()->pending_email);
        $takenStatus = session('status');

        $this->onAdmin(fn () => $user->fresh()->forceFill(['pending_email' => null])->save());

        $availableResponse = $this->actingAsInTenant($user, $tenant)->patch('/profile', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'ganz-neu-'.uniqid().'@example.test',
        ]);

        $availableResponse->assertSessionHasNoErrors();
        $availableStatus = session('status');

        $this->assertSame($takenStatus, $availableStatus);
    }

    /**
     * Bug-Review R4-B: die plattformweite Prüfung oben ist selbst ein
     * Enumerations-Orakel ("wird bereits verwendet" vs. kein Fehler) --
     * jeder eingeloggte Nutzer könnte über sein eigenes Profil beliebige
     * Adressen durchprobieren und daran ablesen, ob sie irgendwo auf der
     * Plattform schon vergeben sind. throttle begrenzt die Anfragerate
     * zusätzlich (Verteidigung in der Tiefe, siehe Test oben für die
     * eigentliche Schließung der Lücke).
     */
    public function test_updating_the_profile_is_rate_limited(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        for ($i = 0; $i < 10; $i++) {
            $response = $this->actingAsInTenant($user, $tenant)->patch('/profile', [
                'first_name' => 'Test',
                'last_name' => 'User',
                'email' => $user->email,
            ]);
            $this->assertNotEquals(429, $response->status());
        }

        $response = $this->actingAsInTenant($user, $tenant)->patch('/profile', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $user->email,
        ]);

        $response->assertStatus(429);
    }

    /**
     * Bug-Review R3-6: entgeht die Adresse der Prüfung bei der Eingabe
     * trotzdem (z. B. weil sie erst NACH dem Absenden anderswo vergeben
     * wurde), griff beim Bestätigen bisher ungefangen der echte,
     * plattformweite UNIQUE-Constraint auf app_user.email -- HTTP 500
     * statt einer verständlichen Meldung.
     */
    public function test_confirming_an_email_that_became_taken_in_the_meantime_shows_an_error_instead_of_crashing(): void
    {
        $otherTenant = $this->createTestTenant();
        $existing = $this->createTenantUser($otherTenant, 'learner', ['email' => 'inzwischen-vergeben-'.uniqid().'@example.test']);

        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $this->onAdmin(fn () => $user->forceFill(['pending_email' => $existing->email])->save());

        $url = URL::temporarySignedRoute(
            'profile.email.confirm',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($existing->email)]
        );

        $response = $this->actingAsInTenant($user, $tenant)->get($url);

        $response->assertRedirect(route('profile.edit'));
        $this->assertFalse($response->isServerError());

        $user->refresh();
        $this->assertNull($user->pending_email);
        $this->assertNotSame($existing->email, $user->email);
    }

    public function test_birthdate_can_be_saved(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $response = $this->actingAsInTenant($user, $tenant)->patch('/profile', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $user->email,
            'birthdate' => '1990-05-20',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect('/profile');
        $this->assertSame('1990-05-20', $user->refresh()->birthdate->format('Y-m-d'));
    }

    public function test_birthdate_must_be_in_the_past(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $response = $this->actingAsInTenant($user, $tenant)->patch('/profile', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $user->email,
            'birthdate' => now()->addYear()->format('Y-m-d'),
        ]);

        $response->assertSessionHasErrors('birthdate');
    }

    public function test_profile_page_lists_bookings_from_entitlements_and_product_purchases(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);
        $course = $this->existingCourse('SRC');

        $this->onAdmin(fn () => Entitlement::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => 'active',
            'valid_from' => now(),
            'source_type' => 'coupon',
            'source_reference' => 'TEST-CODE-1234',
        ]));

        $product = $this->onAdmin(fn () => Product::where('code', 'FAHRSTUNDE-1-EH')->firstOrFail());
        $this->onAdmin(fn () => ProductPurchase::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'source_type' => 'coupon',
            'source_reference' => 'TEST-CODE-5678',
        ]));

        $response = $this->actingAsInTenant($user, $tenant)->get('/profile');

        $response->assertOk();
        $response->assertSee('Deine Buchungen');
        $response->assertSee($course->name);
        $response->assertSee('TEST-CODE-1234');
        $response->assertSee($product->name);
        $response->assertSee('TEST-CODE-5678');
    }

    public function test_profile_update_redirects_back_to_a_given_safe_return_url(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $this->actingAsInTenant($user, $tenant)->get('/profile?return_to=%2Fdashboard');

        $response = $this->actingAsInTenant($user, $tenant)->patch('/profile', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $user->email,
        ]);

        $response->assertRedirect('/dashboard');
    }

    public function test_profile_update_ignores_an_unsafe_return_url(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $this->actingAsInTenant($user, $tenant)->get('/profile?return_to='.urlencode('https://evil.test'));

        $response = $this->actingAsInTenant($user, $tenant)->patch('/profile', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $user->email,
        ]);

        $response->assertRedirect('/profile');
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        Notification::fake();

        $response = $this->actingAsInTenant($user, $tenant)->patch('/profile', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $user->email,
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect('/profile');

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->pending_email);
        Notification::assertNotSentTo($user, ConfirmPendingEmail::class);
    }

    public function test_country_must_be_one_of_the_supported_options(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $response = $this->actingAsInTenant($user, $tenant)->patch('/profile', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $user->email,
            'country' => 'Elbonien',
        ]);

        $response->assertSessionHasErrors('country');
    }

    public function test_user_can_delete_their_account(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $response = $this->actingAsInTenant($user, $tenant)->delete('/profile', [
            'password' => 'password',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $response = $this->actingAsInTenant($user, $tenant)
            ->from('/profile')
            ->delete('/profile', ['password' => 'wrong-password']);

        $response->assertSessionHasErrorsIn('userDeletion', 'password')->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }
}
