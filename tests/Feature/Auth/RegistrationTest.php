<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use InteractsWithTenants;

    public function test_registration_screen_can_be_rendered(): void
    {
        $tenant = $this->createTestTenant();

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/register');

        $response->assertStatus(200);
    }

    /**
     * Bug-Review M3 (Folgefehler): die Registrierung loggt nicht mehr sofort
     * ein (siehe RegisteredUserController) -- sonst würde allein die
     * Antwortstruktur (Dashboard-Redirect + Login vs. bloßer
     * Zurück-Redirect beim Duplikat-Fall) verraten, ob die Adresse schon
     * existiert, unabhängig vom Text der Flash-Nachricht.
     */
    public function test_new_users_can_register_and_are_attached_as_learner_of_the_current_tenant(): void
    {
        $tenant = $this->createTestTenant();
        $email = 'test-'.uniqid().'@example.test';

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/register', [
            'name' => 'Test User',
            'email' => $email,
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
        ]);

        $this->assertGuest();
        $response->assertRedirect();
        $this->assertNotSame(route('dashboard', absolute: false), $response->headers->get('Location'));

        $this->onAdmin(function () use ($tenant, $email) {
            $user = User::where('email', $email)->first();
            $this->assertNotNull($user);
            $this->createdUserIds[] = $user->id;

            $this->assertDatabaseHas('tenant_user', [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role' => 'learner',
            ]);
        });
    }

    /**
     * Bug-Review M3 (Folgefehler): die Duplikat-Meldung war schon neutral
     * formuliert, führte aber zu einer strukturell ANDEREN Antwort als der
     * Erfolgsfall (kein Login, bloßer Zurück-Redirect statt Dashboard) --
     * das allein verrät bereits, ob die Adresse existiert. Beide Fälle
     * müssen deshalb identisch antworten.
     */
    public function test_registering_a_new_and_an_already_registered_email_returns_the_exact_same_response(): void
    {
        $tenant = $this->createTestTenant();
        $existingEmail = 'bereits-da-'.uniqid().'@example.test';
        $this->createTenantUser($tenant, 'learner', ['email' => $existingEmail]);

        $duplicateResponse = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/register', [
            'name' => 'Duplikat',
            'email' => $existingEmail,
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
        ]);
        $this->assertGuest();
        $duplicateStatus = session('status');

        $newEmail = 'ganz-neu-'.uniqid().'@example.test';
        $newResponse = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/register', [
            'name' => 'Neu',
            'email' => $newEmail,
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
        ]);
        $this->assertGuest();
        $newStatus = session('status');

        $this->assertNotNull($duplicateStatus);
        $this->assertSame($duplicateStatus, $newStatus);
        $this->assertSame($duplicateResponse->status(), $newResponse->status());

        $this->onAdmin(function () use ($newEmail) {
            $newUser = User::where('email', $newEmail)->first();
            $this->assertNotNull($newUser);
            $this->createdUserIds[] = $newUser->id;
        });
    }
}
