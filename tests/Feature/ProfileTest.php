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
        Notification::assertSentTo($user, ConfirmPendingEmail::class);
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
