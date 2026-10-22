<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * O1: is_superadmin ließ sich bislang ausschließlich über das Superadmin-
 * UI selbst setzen -- für den allerersten Superadmin einer frischen
 * Installation (noch keiner existiert, also auch kein Zugriff auf dieses
 * UI) blieb nur eine manuelle DB-Änderung per psql/tinker. Der neue
 * Konsolenbefehl app:create-superadmin schließt diese Lücke.
 */
class CreateSuperAdminCommandTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedUsers();

        parent::tearDown();
    }

    public function test_it_creates_a_brand_new_superadmin_account(): void
    {
        $email = 'neuer-superadmin@example.test';

        $exitCode = Artisan::call('app:create-superadmin', [
            'email' => $email,
            '--name' => 'Erster Superadmin',
            '--password' => 'ein-sehr-sicheres-passwort-123',
        ]);

        $this->assertSame(0, $exitCode);

        $user = $this->onAdmin(fn () => User::where('email', $email)->first());
        $this->createdUserIds[] = $user->id;

        $this->assertNotNull($user);
        $this->assertTrue((bool) $user->is_superadmin);
        $this->assertSame('active', $user->status);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('ein-sehr-sicheres-passwort-123', $user->password));
    }

    public function test_it_rejects_a_new_account_with_a_too_short_password(): void
    {
        $email = 'schwaches-passwort@example.test';

        $exitCode = Artisan::call('app:create-superadmin', [
            'email' => $email,
            '--name' => 'Jemand',
            '--password' => 'kurz123',
        ]);

        $this->assertNotSame(0, $exitCode);
        $this->assertNull($this->onAdmin(fn () => User::where('email', $email)->first()));
    }

    public function test_it_rejects_an_invalid_email_address(): void
    {
        $exitCode = Artisan::call('app:create-superadmin', [
            'email' => 'das-ist-keine-email-adresse',
            '--name' => 'Jemand',
            '--password' => 'ein-sehr-sicheres-passwort-123',
        ]);

        $this->assertNotSame(0, $exitCode);
    }

    public function test_it_promotes_an_existing_account_to_superadmin_without_touching_its_password(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'owner', ['password' => bcrypt('das-urspruengliche-passwort')]);

        $exitCode = Artisan::call('app:create-superadmin', [
            'email' => $user->email,
        ]);

        $this->assertSame(0, $exitCode);

        $fresh = $this->onAdmin(fn () => $user->fresh());
        $this->assertTrue((bool) $fresh->is_superadmin);
        $this->assertTrue(Hash::check('das-urspruengliche-passwort', $fresh->password));
    }

    public function test_it_can_reset_the_password_of_an_existing_account_when_explicitly_asked(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'owner', ['password' => bcrypt('das-urspruengliche-passwort')]);

        $exitCode = Artisan::call('app:create-superadmin', [
            'email' => $user->email,
            '--password' => 'ein-ganz-neues-passwort-456',
        ]);

        $this->assertSame(0, $exitCode);

        $fresh = $this->onAdmin(fn () => $user->fresh());
        $this->assertTrue((bool) $fresh->is_superadmin);
        $this->assertTrue(Hash::check('ein-ganz-neues-passwort-456', $fresh->password));
    }
}
